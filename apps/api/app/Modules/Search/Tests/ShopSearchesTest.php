<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Filament\Operator\Pages\ShopSearches;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The searches screen: what found nothing first, a synonym from it, and an update on demand. */
final class ShopSearchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_sees_empty_searches_turns_one_into_a_synonym_and_updates_search(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $inShop = fn (Shop $s, callable $callback) => app(TenantContext::class)->run($s->id, $callback);

        $inShop($shop, function () use ($shop): void {
            CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'עץ אורן מוקצע',
                'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => [],
            ]);
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => now()->toDateString(), 'query' => 'קרש', 'searches' => 6, 'empty' => 6]);
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => now()->toDateString(), 'query' => 'עץ', 'searches' => 9, 'clicks' => 4, 'last_results' => 12]);
        });
        $inShop($other, fn () => SearchTerm::query()->create(['shop_id' => $other->id, 'day' => now()->toDateString(), 'query' => 'של חנות אחרת', 'searches' => 50, 'empty' => 50]));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ShopSearches::class, ['shop' => $shop->id])
            ->assertSeeInOrder(['חיפושים שלא מצאו כלום', 'קרש', 'מילים נרדפות', 'החיפושים הנפוצים', 'עץ'])
            ->assertDontSee('של חנות אחרת')
            ->assertSee('40%')
            ->call('$set', 'synonymTerm', 'קרש')
            ->set('synonymMeans', 'עץ')
            ->call('addSynonym')
            ->assertSet('synonymTerm', '')
            ->call('buildNow')
            ->assertSee('קרש');

        $this->assertSame(['קרש', 'עץ', 'team'], $inShop($shop, fn () => SearchSynonym::query()->sole()->only(['term', 'means', 'origin'])) ? array_values($inShop($shop, fn () => SearchSynonym::query()->sole()->only(['term', 'means', 'origin']))) : []);
        $items = (string) $inShop($shop, fn () => SearchIndex::query()->value('items'));
        $this->assertStringContainsString('קרש', $items, 'the synonym is in the index right after the update');
    }
}
