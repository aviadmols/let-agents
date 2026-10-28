<?php

namespace App\Modules\Demo\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Actions\TestStoreConnection;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\PluginStoreFeed;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Demo\Jobs\ReadContentSiteCurrencyJob;
use App\Modules\Demo\Support\ContentSite;
use App\Modules\Demo\Support\PublicSite;
use App\Modules\Demo\Support\PublicStoreFeed;
use App\Modules\Demo\Support\WordPressSite;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A WordPress site with no store, read for a demo (Gil Travel, 27.10): its tours were custom types whose text lived in
 * a page builder's template, so the API gave them an empty body; every page carried the same menu, banner and "Make an
 * Inquiry"; and a news type linked to the tours' own pages. What it offers becomes products, what it writes articles,
 * nothing else is read, and a price is only ever the one the item's page states by its title.
 */
final class ContentSiteTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'tours.test';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn (Request $request) => $this->site($request));
    }

    public function test_a_wordpress_site_with_no_store_is_read_as_a_content_site(): void
    {
        $read = PublicSite::read(self::HOST);
        $this->assertSame(PublicSite::WORDPRESS, $read['platform'] ?? null);
        $this->assertSame('USD', $read['currency'], 'the currency its offered items\' prices state');
    }

    public function test_a_store_searches_as_it_did_and_only_a_content_site_reads_more_with_its_articles_and_currency(): void
    {
        $token = 'lat_'.str_repeat('7', 48);
        $shop = Shop::factory()->create(['currency' => 'USD']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://www.tours.test', 'access_token' => $token]));
        app(BuildSearchIndex::class)->handle($shop->id);
        $config = fn (): array => $this->getJson('/api/v1/search/'.SiteKeys::site($token).'/index', ['Origin' => 'https://www.tours.test'])->assertOk()->json('config');

        $store = $config();
        $this->assertSame([true, 'product_page', 0, null], [$store['cart'], $store['cardAction'], $store['guidesAfter'], $store['currency']], 'a store: its cart, its button, its guides after all its products and its own currency, as they were');

        ContentSite::settle($shop->id);
        Cache::flush();
        $content = $config();
        $this->assertSame([false, 'read_more', 4, 'USD'], [$content['cart'], $content['cardAction'], $content['guidesAfter'], $content['currency']]);
        $this->assertTrue(Features::enabled('search.content_by_meaning', $shop->id), 'its articles found by what they say');
    }

    public function test_a_site_connected_with_the_plugin_and_no_woocommerce_is_read_as_its_demo_is_and_set_once(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://tours.test', 'access_token' => 'lat_'.str_repeat('6', 48)]));

        app(TestStoreConnection::class)->handle($connection);
        $connection->refresh();
        $this->assertFalse($connection->info('woocommerce.active'));
        $this->assertSame(['read_more', 4, 'USD'], [Settings::get('search.card_action', $shop->id), Settings::get('search.guides_after', $shop->id), $shop->fresh()->currency], 'set as a content site when first seen');

        $feed = app(PluginStoreFeed::class);
        $products = array_merge(...iterator_to_array($feed->products($connection), false));
        $this->assertSame(['101'], array_column($products, 'external_id'), 'what it offers, by the same reader as its demo');
        $this->assertSame(['post'], $feed->contentTypes($connection));
        $this->assertSame(['Italy'], array_column($feed->categories($connection), 'name'));
        $this->assertNull($feed->changedProducts($connection, now()->subDay()), 'no quick read of changes: the nightly read sees them');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/let-agents/v1/feed/'));

        // what the team changes after stays theirs: a second check sets nothing again
        Settings::set('search.guides_after', 0, $shop->id);
        app(TestStoreConnection::class)->handle($connection);
        $this->assertSame(0, Settings::get('search.guides_after', $shop->id));
    }

    public function test_connect_answers_at_once_and_the_currency_is_read_in_the_background(): void
    {
        // Gil Travel, 28.10: "Connect" read the whole site for its currency and looked like it did nothing
        Queue::fake();
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://tours.test', 'access_token' => 'lat_'.str_repeat('7', 48)]));

        app(TestStoreConnection::class)->handle($connection);

        $this->assertSame('read_more', Settings::get('search.card_action', $shop->id), 'its search set at once');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/let-agents/v1/status'));
        Queue::assertPushed(ReadContentSiteCurrencyJob::class, fn (ReadContentSiteCurrencyJob $job): bool => $job->shopId === $shop->id && $job->host === 'tours.test');
    }

    public function test_a_store_with_woocommerce_is_read_from_its_plugin_as_it_always_was(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://tours.test', 'access_token' => 'lat_'.str_repeat('5', 48)]));
        $connection->forceFill(['site_info' => ['woocommerce' => ['active' => true]]]);

        try {
            iterator_to_array(app(PluginStoreFeed::class)->products($connection), false);
        } catch (StoreFeedUnavailable) {
            // the fake site's plugin has no store: what matters is where it asked
        }
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/let-agents/v1/feed/products'));
        $this->assertSame('product_page', Settings::get('search.card_action', $shop->id), 'a store\'s search untouched');
    }

    public function test_what_it_offers_and_what_it_writes_are_told_apart_and_nothing_else_is_read(): void
    {
        $types = WordPressSite::read(self::HOST)['types'];

        $this->assertSame(WordPressSite::OFFERING, $types['tour']['role'] ?? null, 'a price, a way to ask and a length: offered');
        $this->assertSame(WordPressSite::ARTICLE, $types['post']['role'] ?? null);
        $this->assertArrayNotHasKey('page', $types, 'the site\'s internal pages are never read');
        $this->assertArrayNotHasKey('team_member', $types, 'not in its sitemap: not for searchers');
        $this->assertArrayNotHasKey('podcast', $types, 'neither offered nor written: not read');
        $this->assertContains('Make an Inquiry', WordPressSite::read(self::HOST)['template'], 'the button every page carries is the template\'s');
    }

    public function test_an_offered_item_is_a_product_with_its_own_words_and_only_the_price_by_its_title(): void
    {
        $feed = app(PublicStoreFeed::class);
        $connection = $this->connection();

        $products = array_merge(...iterator_to_array($feed->products($connection), false));

        $this->assertSame(['101'], array_column($products, 'external_id'), 'the news item linking to the tour\'s page adds it no second time');
        $tour = $products[0];
        $this->assertSame('Rome & Florence – 8 Days', $tour['title']);
        $this->assertSame(['USD', '1200'], [$tour['price']['currency'], $tour['price']['price']], 'its own price, never the $999 of another tour further down');
        $this->assertFalse($tour['purchasable'], 'asked for on the site, never bought in a cart');
        $this->assertSame('Tours', $tour['product_type']);
        $this->assertSame([['id' => 'wp:category:5', 'name' => 'Italy', 'slug' => 'italy', 'path' => ['Italy']]], $tour['categories']);
        $this->assertSame(['Food', 'Europe'], $tour['tags'], 'its tags and the trail its page is filed under');
        $this->assertSame('https://tours.test/img/rome-768.jpg', $tour['images'][0]['url'], 'a picture fit for a card');
        $this->assertStringContainsString('Day 1: Rome', $tour['description'], 'read from its page: the API gave no body');
        foreach (['Make an Inquiry', 'We use cookies', 'Lorem ipsum', '.slider {', 'Home › Europe'] as $noise) {
            $this->assertStringNotContainsString($noise, $tour['description'], "no {$noise} in its words");
        }
        $this->assertSame(1, substr_count($tour['description'], 'Itinerary'), 'a line the page repeats, once');

        $plain = WordPressSite::price("Day 3: Venice\nOur other tours from $999");
        $this->assertSame(['USD', '999'], $plain, 'a price is read wherever it is given; the product reads its first lines only');
    }

    public function test_what_it_writes_is_its_articles_and_its_categories_show_no_archive_page(): void
    {
        $feed = app(PublicStoreFeed::class);
        $connection = $this->connection();

        $this->assertSame(['post'], $feed->contentTypes($connection));
        $articles = array_merge(...iterator_to_array($feed->content($connection, 'post'), false));
        $this->assertSame(['201'], array_column($articles, 'external_id'));
        $this->assertSame('post', $articles[0]['type']);
        $this->assertStringStartsWith('Packing for a long trip', $articles[0]['text']);
        $this->assertSame([], iterator_to_array($feed->content($connection, 'page'), false), 'no internal pages');

        $categories = $feed->categories($connection);
        $this->assertSame(['Italy'], array_column($categories, 'name'));
        $this->assertSame(0, $categories[0]['product_count'], 'an archive is the site\'s internal page, never a result of its own');
    }

    private function connection(): StoreConnection
    {
        return (new StoreConnection)->forceFill(['site_url' => 'https://'.self::HOST, 'site_info' => ['demo' => ['platform' => PublicSite::WORDPRESS, 'currency' => null]]]);
    }

    /** The fake site: a WordPress with tours, posts, a team, a podcast, pages and a news ticker pointing at the tours. */
    private function site(Request $request)
    {
        $url = parse_url($request->url());
        $path = $url['path'] ?? '/';
        parse_str($url['query'] ?? '', $query);
        $page = (int) ($query['page'] ?? 1);
        $sample = (int) ($query['per_page'] ?? 0) === 3;

        return match (true) {
            // the plugin, on this site with no WooCommerce: its status says so, and its store routes answer as they do
            $path === '/wp-json/let-agents/v1/status' => Http::response(['data' => ['plugin' => ['version' => '0.14.1', 'content_post_types' => ['post']], 'woocommerce' => ['active' => false], 'counts' => ['products' => ['publish' => 0]]]]),
            str_starts_with($path, '/wp-json/let-agents/v1/feed/') => Http::response(['code' => 'let_agents_woocommerce_inactive', 'message' => 'WooCommerce is not active on this site.'], 503),
            $path === '/' => Http::response('<html lang="en"><head><title>Tours Test</title></head><body><link href="/wp-content/x.css"></body></html>'),
            $path === '/products.json', str_starts_with($path, '/wp-json/wc/') => Http::response('not found', 404),
            $path === '/wp-json/' => Http::response(['namespaces' => ['oembed/1.0', 'wp/v2']]),
            $path === '/sitemap_index.xml' => Http::response('<?xml version="1.0"?><sitemapindex>'.implode('', array_map(fn (string $t): string => "<sitemap><loc>https://tours.test/{$t}-sitemap.xml</loc></sitemap>", ['post', 'page', 'tour', 'news', 'podcast'])).'</sitemapindex>'),
            in_array($path, ['/wp-sitemap.xml', '/sitemap.xml'], true) => Http::response('not found', 404),
            $path === '/wp-json/wp/v2/types' => Http::response([
                'post' => ['name' => 'Posts', 'rest_base' => 'posts'], 'page' => ['name' => 'Pages', 'rest_base' => 'pages'], 'attachment' => ['name' => 'Media', 'rest_base' => 'media'],
                'tour' => ['name' => 'Tours', 'rest_base' => 'tours'], 'news' => ['name' => 'News', 'rest_base' => 'news'], 'podcast' => ['name' => 'Podcasts', 'rest_base' => 'podcasts'],
                'team_member' => ['name' => 'Team', 'rest_base' => 'team'], 'elementor_library' => ['name' => 'Templates', 'rest_base' => 'elementor_library'],
            ]),
            $path === '/wp-json/wp/v2/taxonomies' => Http::response(['category' => ['hierarchical' => true, 'rest_base' => 'categories', 'types' => ['post', 'tour']], 'post_tag' => ['hierarchical' => false, 'rest_base' => 'tags', 'types' => ['post', 'tour']]]),
            $path === '/wp-json/wp/v2/categories' => Http::response($page === 1 ? [['id' => 5, 'name' => 'Italy', 'slug' => 'italy', 'parent' => 0, 'link' => 'https://tours.test/category/italy/']] : []),
            $path === '/wp-json/wp/v2/tours' => $sample
                ? Http::response([['id' => 101, 'link' => 'https://tours.test/tour/rome/'], ['id' => 102, 'link' => 'https://tours.test/tour/rome/'], ['id' => 103, 'link' => 'https://tours.test/tour/rome/']], 200, ['X-WP-Total' => '1'])
                : Http::response($page === 1 ? [$this->item(101, 'Rome &amp; Florence &#8211; 8 Days', 'https://tours.test/tour/rome/', '')] : []),
            $path === '/wp-json/wp/v2/news' => $sample
                ? Http::response([['id' => 301, 'link' => 'https://tours.test/tour/rome/']], 200, ['X-WP-Total' => '1'])
                : Http::response($page === 1 ? [$this->item(301, 'New: Rome', 'https://tours.test/tour/rome/', '')] : []),
            $path === '/wp-json/wp/v2/posts' => $sample
                ? Http::response([['id' => 201, 'link' => 'https://tours.test/blog/packing/'], ['id' => 202, 'link' => 'https://tours.test/blog/packing/'], ['id' => 203, 'link' => 'https://tours.test/blog/packing/']], 200, ['X-WP-Total' => '1'])
                : Http::response($page === 1 ? [$this->item(201, 'Packing for a long trip', 'https://tours.test/blog/packing/', '<p>'.str_repeat('Packing for a long trip is easier with a list. ', 8).'</p>')] : []),
            $path === '/wp-json/wp/v2/podcasts' => $sample
                ? Http::response([['id' => 401, 'link' => 'https://tours.test/podcast/one/'], ['id' => 402, 'link' => 'https://tours.test/podcast/one/'], ['id' => 403, 'link' => 'https://tours.test/podcast/one/']], 200, ['X-WP-Total' => '3'])
                : Http::response([]),
            $path === '/tour/rome/' => Http::response($this->page('<nav><p>Home › Europe › Rome &amp; Florence</p></nav><h1>Rome &amp; Florence – 8 Days</h1><p>From $1,200</p><p>8 Days</p><div>Itinerary</div><div>Itinerary</div>'
                .'<p>Day 1: Rome</p><p>Lorem ipsum dolor sit amet</p><p>.slider { display: flex; }</p><p>Day 2: Florence</p>'.str_repeat('<p>A walk through the old town.</p>', 12).'<p>Our other tours from $999</p>', true)),
            $path === '/blog/packing/' => Http::response($this->page('<h1>Packing for a long trip</h1><p>'.str_repeat('Packing for a long trip is easier with a list. ', 8).'</p>', false, true)),
            $path === '/podcast/one/' => Http::response($this->page('<h1>Episode one</h1><p>We talk about travel.</p>')),
            default => Http::response('not found', 404),
        };
    }

    /** A page of the site: its template around its own words. */
    private function page(string $own, bool $crumbs = false, bool $article = false): string
    {
        $head = '<head><title>x</title>'.($article ? '<meta property="article:published_time" content="2026-01-01T00:00:00+00:00">' : '')
            .($crumbs ? '<script type="application/ld+json">{"@graph":[{"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home"},{"@type":"ListItem","position":2,"name":"Europe"},{"@type":"ListItem","position":3,"name":"Rome"}]}]}</script>' : '').'</head>';

        return '<html>'.$head.'<body><div class="menu"><p>Home</p><p>Tours</p><p>Blog</p><p>Make an Inquiry</p><p>Book now</p><p>Our 9 Nights Israel tour is back</p></div>'
            .'<div class="content">'.$own.'</div><div class="footer"><p>We use cookies to improve your experience.</p><p>Accept All</p><p>© Tours Test</p></div></body></html>';
    }

    /** @return array<string, mixed> one item of the site's API, with its picture and terms */
    private function item(int $id, string $title, string $link, string $content): array
    {
        return ['id' => $id, 'slug' => 'item-'.$id, 'link' => $link, 'date_gmt' => '2026-01-01T00:00:00', 'modified_gmt' => '2026-02-01T00:00:00',
            'title' => ['rendered' => $title], 'content' => ['rendered' => $content], 'excerpt' => ['rendered' => ''],
            '_embedded' => [
                'wp:featuredmedia' => [['source_url' => 'https://tours.test/img/rome.jpg', 'media_details' => ['sizes' => ['medium_large' => ['source_url' => 'https://tours.test/img/rome-768.jpg']]]]],
                'wp:term' => [[['id' => 5, 'name' => 'Italy', 'slug' => 'italy', 'taxonomy' => 'category']], [['id' => 9, 'name' => 'Food', 'slug' => 'food', 'taxonomy' => 'post_tag']]],
            ]];
    }
}
