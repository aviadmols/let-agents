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
 * "מרחיק יונים" where the spike is written "להרחקת יונים": no product says both words, and by meaning it is too far to show.
 * The word few products say ("יונים") is searched alone, and only what says it is shown (Gueta, 17.11).
 */
final class TellingWordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_word_few_products_say_is_searched_alone_when_nothing_holds_them_all(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $titles = ['1' => 'דוקרן נירוסטה להרחקת יונים', '2' => 'מקדחה רוטטת', '3' => 'מקדחה נטענת', '4' => 'מרסס גינה', '5' => 'מרסס צבע', '6' => 'מברשת לדק'];
            foreach ($titles as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $search = fn (string $q): array => app(SearchCatalog::class)->handle($shop->id, $q, null, false, null, false);

        $found = $search('מרחיק יונים');
        $this->assertSame(['1'], array_column($found['groups']['product'], 'external_id'));
        $this->assertSame('יונים', $found['loosened']['query'] ?? null, 'said what it searched for');
        $this->assertSame([], array_column($search('מקדחה יונים')['groups']['product'], 'external_id'), 'a kind the store sells is never left out');
    }
}
