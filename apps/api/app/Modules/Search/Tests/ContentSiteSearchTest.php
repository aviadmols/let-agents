<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A site of articles and pages with no shop at all: the search box works the same, its results
 * are the site's own articles and pages.
 */
final class ContentSiteSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_9999999999999999999999999999999999999999999999999';

    public function test_a_site_with_no_products_is_searched_by_its_articles_and_pages(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.blog.test', 'access_token' => self::TOKEN,
        ]));
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['10' => 'איך בונים פרגולה מעץ', '11' => 'מדריך לבחירת עץ לבנייה בחוץ', '12' => 'צור קשר'] as $id => $title) {
                CatalogContent::query()->create([
                    'shop_id' => $shop->id, 'type' => $id === '12' ? 'page' : 'post', 'external_id' => (string) $id, 'title' => $title,
                    'url' => "https://www.blog.test/?p={$id}", 'terms' => [], 'hash' => "h{$id}",
                ]);
            }
        });

        $run = app(BuildSearchIndex::class)->handle($shop->id);
        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame(0, $run->output['counts']['product']);
        $this->assertSame(3, $run->output['counts']['content']);

        $site = SiteKeys::site(self::TOKEN);
        $index = $this->getJson("/api/v1/search/{$site}/index", ['Origin' => 'https://www.blog.test'])->assertOk()->json();
        $this->assertTrue($index['enabled']);
        $this->assertSame(['content'], array_values(array_unique(array_column($index['items'], 't'))));

        $result = $this->getJson("/api/v1/search/{$site}?q=".urlencode('פרגולה'), ['Origin' => 'https://www.blog.test'])->assertOk()->json();
        $this->assertSame([], $result['groups']['product']);
        $this->assertSame('איך בונים פרגולה מעץ', $result['groups']['content'][0]['title']);
    }
}
