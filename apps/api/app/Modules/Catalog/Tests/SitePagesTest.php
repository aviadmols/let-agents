<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadSitePages;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The pages of the store's own site the feed did not bring are read for the assistant: its sitemap's
 * pages (not products or categories) and, on Shopify, its policies; never twice, and gone when they
 * leave the site. So a return policy is found wherever the store wrote it.
 */
final class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = '<html><head><title>x</title></head><body><header><nav>תפריט</nav></header><main><h1>מדיניות החזרות</h1><div class="rte"><p>ניתן להחזיר מוצר תוך 14 יום מיום הקבלה, באריזה המקורית ובצירוף חשבונית.</p><ul><li>מוצר שיוצר בהתאמה אישית לא יוחזר.</li><li>דמי ביטול 5%.</li></ul></div></main><footer>כל הזכויות</footer></body></html>';

    public function test_the_sitemaps_pages_and_the_shopify_policies_are_read_once_and_leave_with_the_site(): void
    {
        $shop = Shop::factory()->create(['domain' => 'rocks.test', 'platform' => ShopPlatform::Shopify]);
        $page = fn (string $title, string $text): string => '<html><body><main><h1>'.$title.'</h1><p>'.str_repeat($text.' ', 8).'</p></main></body></html>';
        $sitemap = fn (array $locs, bool $index = false): string => '<?xml version="1.0"?><'.($index ? 'sitemapindex' : 'urlset').'>'.implode('', array_map(fn (string $l): string => ($index ? '<sitemap>' : '<url>').'<loc>'.$l.'</loc>'.($index ? '</sitemap>' : '</url>'), $locs)).'</'.($index ? 'sitemapindex' : 'urlset').'>';

        $routes = [];
        Http::fake(function ($request) use (&$routes) {
            return $routes[(string) $request->url()] ?? Http::response('', 404);
        });
        $routes = [
            'https://rocks.test/sitemap.xml' => Http::response($sitemap(['https://rocks.test/sitemap_pages_1.xml', 'https://rocks.test/sitemap_products_1.xml'], true)),
            'https://rocks.test/sitemap_pages_1.xml' => Http::response($sitemap(['https://rocks.test/pages/faq', 'https://rocks.test/pages/about', 'https://rocks.test/pages/ring-sizes', 'https://other.test/pages/x'])),
            'https://rocks.test/sitemap_products_1.xml' => Http::response($sitemap(['https://rocks.test/products/ring'])),
            'https://rocks.test/pages/faq' => Http::response($page('שאלות נפוצות', 'כמה זמן לוקח משלוח? עד שלושה ימי עסקים.')),
            'https://rocks.test/pages/about' => Http::response($page('אודות', 'חנות תכשיטים בתל אביב מאז 1990.')),
            'https://rocks.test/pages/ring-sizes' => Http::response($page('מדריך מידות', 'מידות טבעות 48 עד 62, אפשר להגדיל ולהקטין.')),
            'https://rocks.test/policies/refund-policy' => Http::response(self::POLICY),
        ];

        // The feed already brought the about page: not read twice.
        app(TenantContext::class)->run($shop->id, fn () => CatalogContent::query()->create([
            'shop_id' => $shop->id, 'type' => CatalogContent::PAGE, 'external_id' => '77', 'title' => 'אודות', 'url' => 'https://www.rocks.test/pages/about/', 'body' => 'x', 'hash' => 'h',
        ]));

        $run = app(ReadSitePages::class)->handle($shop->id);

        $this->assertSame(3, $run->output['new'], 'the FAQ, the sizes guide and the return policy');
        $this->assertSame(1, $run->output['skipped'], 'the about page the feed brought');
        $pages = app(TenantContext::class)->run($shop->id, fn () => CatalogContent::query()->where('external_id', 'like', ReadSitePages::PREFIX.'%')->get()->keyBy('title'));
        $this->assertEqualsCanonicalizing(['שאלות נפוצות', 'מדריך מידות', 'מדיניות החזרות'], $pages->keys()->all(), 'no product, nothing of another site');
        $policy = $pages['מדיניות החזרות'];
        $this->assertStringContainsString('ניתן להחזיר מוצר תוך 14 יום', $policy->body);
        $this->assertStringContainsString('- מוצר שיוצר בהתאמה אישית לא יוחזר.', $policy->body, 'its list kept as a list');
        $this->assertStringNotContainsString('תפריט', $policy->body, 'not the header or the footer');
        $this->assertSame(CatalogContent::PAGE, $policy->type);

        // Again, unchanged: nothing is written; a page that left the site leaves the catalogue.
        $routes = [
            'https://rocks.test/sitemap.xml' => Http::response($sitemap(['https://rocks.test/pages/faq'])),
            'https://rocks.test/pages/faq' => Http::response($page('שאלות נפוצות', 'כמה זמן לוקח משלוח? עד שלושה ימי עסקים.')),
            'https://rocks.test/policies/refund-policy' => Http::response(self::POLICY),
        ];
        $again = app(ReadSitePages::class)->handle($shop->id);

        $this->assertSame([0, 2, 1], [$again->output['new'], $again->output['same'], $again->output['removed']], 'the sizes guide left the site');
    }
}
