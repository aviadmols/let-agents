<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Contracts\StoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A post reaches each platform's blog as a draft: a hidden Shopify article with its search fields
 * and questions, or a WordPress draft through the plugin's one signed write route.
 */
final class ShopifyBlogTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_blogtest00000000000000000000000000000000000000';

    /** @var list<array{query: string, variables: array<string, mixed>}> */
    private array $asked = [];

    public function test_a_shopify_post_is_a_hidden_article_with_its_search_fields_and_questions(): void
    {
        [$connection, $install] = $this->shopify('read_products,read_content,write_content');
        Http::fake(fn (Request $request) => $this->shopifyAnswer($request));

        $sent = app(StoreBlog::class)->push($connection, $this->blogPost(), null);

        $this->assertSame('gid://shopify/Article/555', $sent['id']);
        $this->assertSame('https://admin.shopify.com/store/rocks/content/articles/555', $sent['admin_url']);
        $create = collect($this->asked)->first(fn (array $a): bool => str_contains($a['query'], 'articleCreate'));
        $article = $create['variables']['article'];
        $this->assertSame(['gid://shopify/Blog/2', false, 'סיכום קצר', ['name' => 'Rocks and Gold']], [$article['blogId'], $article['isPublished'], $article['summary'], $article['author']], 'the news blog, hidden');
        $this->assertSame(['url' => 'https://cdn.test/1.jpg', 'altText' => 'טבעת'], $article['image']);
        $metafields = collect($article['metafields'])->keyBy('key');
        $this->assertSame('כותרת לגוגל', $metafields['title_tag']['value']);
        $this->assertSame('תיאור לגוגל', $metafields['description_tag']['value']);
        $this->assertSame([['q' => 'שאלה?', 'a' => 'תשובה.']], json_decode($metafields['faq']['value'], true));
        $this->assertSame(['let_agents', 'json'], [$metafields['faq']['namespace'], $metafields['faq']['type']]);

        // Sent again while hidden: updated. Published by the owner: left as it is.
        app(StoreBlog::class)->push($connection, $this->blogPost(), 'gid://shopify/Article/555');
        $this->assertTrue(collect($this->asked)->contains(fn (array $a): bool => str_contains($a['query'], 'articleUpdate')));
        $this->published = true;
        $before = count($this->asked);
        app(StoreBlog::class)->push($connection, $this->blogPost(), 'gid://shopify/Article/555');
        $this->assertFalse(collect(array_slice($this->asked, $before))->contains(fn (array $a): bool => str_contains($a['query'], 'articleUpdate')));
    }

    public function test_a_shopify_store_without_the_blog_permission_is_told_so_and_shopify_is_not_asked(): void
    {
        [$connection] = $this->shopify('read_products,read_content');
        Http::fake();

        try {
            app(StoreBlog::class)->push($connection, $this->blogPost(), null);
            $this->fail('a store without write_content takes no post');
        } catch (StoreFeedUnavailable $e) {
            $this->assertSame('missing_scope', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_wordpress_post_goes_through_the_plugins_signed_route(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://wp.test', 'access_token' => self::TOKEN]));
        Http::fake([
            'wp.test/wp-json/let-agents/v1/posts' => Http::response(['id' => '901', 'status' => 'draft', 'url' => 'https://wp.test/?p=901&preview=true', 'edit_url' => 'https://wp.test/wp-admin/post.php?post=901&action=edit']),
        ]);

        $sent = app(StoreBlog::class)->push($connection, $this->blogPost(), null);

        $this->assertSame(['901', 'https://wp.test/wp-admin/post.php?post=901&action=edit'], [$sent['id'], $sent['admin_url']]);
        Http::assertSent(function (Request $request): bool {
            $signature = SiteKeys::signature(self::TOKEN, $request->header('X-LetAgents-Timestamp')[0], 'POST', '/let-agents/v1/posts', $request->body());

            return $request->method() === 'POST'
                && $request->header('X-LetAgents-Token')[0] === self::TOKEN
                && hash_equals($signature, $request->header('X-LetAgents-Signature')[0])
                && $request['post']['meta_description'] === 'תיאור לגוגל';
        });
    }

    public function test_a_wordpress_site_with_an_older_plugin_is_told_to_update_it(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://old.test', 'access_token' => self::TOKEN]));
        Http::fake(['old.test/*' => Http::response(['code' => 'rest_no_route', 'message' => 'No route was found'], 404)]);

        try {
            app(StoreBlog::class)->push($connection, $this->blogPost(), null);
            $this->fail('an old plugin takes no post');
        } catch (StoreFeedUnavailable $e) {
            $this->assertSame('plugin_outdated', $e->reason);
        }
    }

    private bool $published = false;

    /** @return array{0: StoreConnection, 1: ShopifyInstall} */
    private function shopify(string $scopes): array
    {
        config(['services.shopify.version' => '2026-07']);
        $shop = Shop::factory()->create(['platform' => ShopPlatform::Shopify]);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'platform' => 'shopify', 'site_url' => 'https://rocks.test', 'access_token' => 'lat_'.str_repeat('c', 48)]));
        $install = ShopifyInstall::query()->create(['shop_id' => $shop->id, 'shop_domain' => 'rocks.myshopify.com', 'access_token' => 'shpat_test', 'scopes' => $scopes, 'installed_at' => now()]);

        return [$connection, $install];
    }

    private function shopifyAnswer(Request $request): mixed
    {
        $query = (string) $request['query'];
        $this->asked[] = ['query' => $query, 'variables' => (array) data_get($request->data(), 'variables', [])];
        $article = ['id' => 'gid://shopify/Article/555', 'handle' => 'how-to', 'blog' => ['handle' => 'news'], 'isPublished' => $this->published];

        return Http::response(['data' => match (true) {
            str_contains($query, 'blogs(') => ['blogs' => ['nodes' => [['id' => 'gid://shopify/Blog/1', 'handle' => 'events'], ['id' => 'gid://shopify/Blog/2', 'handle' => 'news']]]],
            str_contains($query, 'articleCreate') => ['articleCreate' => ['article' => $article, 'userErrors' => []]],
            str_contains($query, 'articleUpdate') => ['articleUpdate' => ['article' => $article, 'userErrors' => []]],
            str_contains($query, 'article(') => ['article' => $article],
            default => [],
        }]);
    }

    /** @return array<string, mixed> */
    private function blogPost(): array
    {
        return [
            'title' => 'איך בוחרים טבעת', 'html' => '<p>טקסט</p>', 'excerpt' => 'סיכום קצר', 'seo_title' => 'כותרת לגוגל', 'meta_description' => 'תיאור לגוגל',
            'focus_keyword' => 'טבעת', 'tags' => ['טבעות'], 'faq' => [['q' => 'שאלה?', 'a' => 'תשובה.']], 'image' => ['url' => 'https://cdn.test/1.jpg', 'alt' => 'טבעת'],
            'author' => 'Rocks and Gold', 'post_id' => '01post',
        ];
    }
}
