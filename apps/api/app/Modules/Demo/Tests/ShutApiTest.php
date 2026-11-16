<?php

namespace App\Modules\Demo\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Support\PublicSite;
use App\Modules\Demo\Support\PublicStoreFeed;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A WordPress site whose security shuts the API's address (/wp-json/wp/v2/… answers 403) while WordPress answers the very
 * same at ?rest_route=, and its store API stays open (Profimex, 16.11): its demo read the products and not one of its
 * posts or pages. Read at the other address, they are there; a site that answers neither is read as before.
 */
final class ShutApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'shut.test';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/wp-json/wp/v2/')) {
                return Http::response('<html>403 Forbidden</html>', 403);
            }
            $route = (string) ($query['rest_route'] ?? '');
            if ($route === '/wp/v2/posts') {
                return (int) ($query['page'] ?? 1) > 1 ? Http::response([], 200) : Http::response([['id' => 7, 'link' => 'https://shut.test/report/', 'slug' => 'report', 'title' => ['rendered' => 'דו&quot;ח שוק'], 'content' => ['rendered' => '<p>מה קרה בשוק השבוע.</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2026-11-01', 'modified' => '2026-11-02']], 200, ['X-WP-Total' => '1']);
            }
            if ($route === '/wp/v2/pages') {
                return (int) ($query['page'] ?? 1) > 1 ? Http::response([], 200) : Http::response([['id' => 9, 'link' => 'https://shut.test/about/', 'slug' => 'about', 'title' => ['rendered' => 'אודות'], 'content' => ['rendered' => '<p>מי אנחנו.</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2026-01-01', 'modified' => '2026-01-02']], 200);
            }

            return Http::response('<html>home</html>', 200);
        });
    }

    public function test_the_api_shut_at_its_address_is_read_at_wordpress_other_address(): void
    {
        $posts = PublicSite::json(self::HOST, '/wp-json/wp/v2/posts', ['per_page' => 1]);
        $this->assertSame(7, $posts[0]['id'] ?? null);
        $this->assertSame('1', PublicSite::get(self::HOST, '/wp-json/wp/v2/posts', ['per_page' => 1])?->header('X-WP-Total'), 'its count too, as the type is weighed by it');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'rest_route=%2Fwp%2Fv2%2Fposts') && str_contains($request->url(), 'per_page=1'));
        $this->assertNull(PublicSite::json(self::HOST, '/wp-json/wp/v2/users'), 'what neither address answers as JSON is none, as before');
    }

    public function test_a_store_demo_reads_its_posts_and_pages_through_it(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://'.self::HOST, 'access_token' => 'lat_'.str_repeat('6', 48)]));
        $connection->forceFill(['platform' => 'demo', 'site_info' => ['demo' => ['platform' => PublicSite::WOOCOMMERCE]]]);
        $feed = app(PublicStoreFeed::class);

        $this->assertSame(['page', 'post'], $feed->contentTypes($connection));
        $posts = array_merge(...iterator_to_array($feed->content($connection, 'post'), false));
        $pages = array_merge(...iterator_to_array($feed->content($connection, 'page'), false));
        $this->assertSame(['דו"ח שוק'], array_column($posts, 'title'));
        $this->assertSame(['אודות'], array_column($pages, 'title'));
        $this->assertStringContainsString('מה קרה בשוק', $posts[0]['text']);
    }
}
