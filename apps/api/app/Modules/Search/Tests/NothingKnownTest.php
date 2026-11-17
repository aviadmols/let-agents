<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "חולצה" in a jewellery store: the store has no word close to it, and the spelling still found 412 chains by "חוליות",
 * shown as "the nearest by meaning" (Rocks, Gueta, 17.11). A word the store does not know finds nothing by its letters.
 */
final class NothingKnownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_word_the_store_has_nothing_close_to_finds_nothing_by_its_letters(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'שרשרת חוליות שטוחות', '2' => 'צמיד חוליות רחב', '3' => 'טבעת יהלום', '4' => 'מכנסי זואי קצרים'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $found = fn (string $q): array => app(SearchCatalog::class)->handle($shop->id, $q, ['product'], false, null, true);
        $ids = fn (string $q): array => array_column($found($q)['groups']['product'], 'external_id');

        $this->assertSame(['חולצה'], $found('חולצה')['unknown']);
        $this->assertSame([], $ids('חולצה'), 'chains are no shirt');
        $this->assertEqualsCanonicalizing(['1', '2'], $ids('חוליות'), 'the store\'s own word is found as ever');
        $this->assertContains('4', $ids('מכנסיקצרים'), 'words typed with no space still begin with the store\'s word');
    }
}
