<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\CountSearch;
use App\Modules\Search\Actions\LearnFromSearches;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchLearned;
use App\Modules\Search\Models\SearchSignal;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A store learns from what its own different shoppers do with its searches, nightly, in code; one shopper teaches nothing. */
final class LearnFromSearchesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['1' => 'חולצה לבנה Basic', '2' => 'חולצה לבנה Mateo', '3' => 'חולצה לבנה Shaw'] as $id => $title) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [], 'price' => 100,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_what_different_shoppers_open_and_buy_for_a_search_comes_first_and_chips_they_take_off_go(): void
    {
        $count = app(CountSearch::class);
        $vid = fn (int $n): string => 'anon-'.str_pad((string) $n, 20, 'x');
        app(TenantContext::class)->run($this->shop->id, function () use ($count, $vid): void {
            foreach ([1, 2, 3] as $n) {
                $count->signal($this->shop->id, 'click', 'חולצה לבנה', 'p:3', $vid($n));
                $count->signal($this->shop->id, 'chip_off', 'פטריה לפוקוס', 'פטריה', $vid($n));
            }
            $count->signal($this->shop->id, 'add', 'חולצה לבנה', 'p:3', $vid(1));
            // one shopper, again and again: still one
            foreach (range(1, 9) as $i) {
                $count->signal($this->shop->id, 'click', 'חולצה לבנה', 'p:2', $vid(9));
            }
        });

        $signals = app(TenantContext::class)->run($this->shop->id, fn () => SearchSignal::query()->where('kind', 'click')->pluck('visitors', 'value')->all());
        ksort($signals);
        $this->assertSame(['p:2' => 1, 'p:3' => 3], $signals, 'each shopper once a day');

        app(LearnFromSearches::class)->handle($this->shop->id);

        $learned = app(TenantContext::class)->run($this->shop->id, fn () => SearchLearned::query()->orderBy('kind')->get(['kind', 'query', 'value'])->map(fn ($l) => $l->kind.':'.$l->value)->all());
        $this->assertSame(['boost:p:3', 'no_chip:פטריה'], $learned, 'what three shoppers did, never what one did nine times');

        $first = app(SearchCatalog::class)->handle($this->shop->id, 'חולצה לבנה', count: false)['groups']['product'][0]['external_id'] ?? null;
        $this->assertSame('3', $first, 'the shirt shoppers opened and bought for these words, first');

        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
        $this->assertSame(['פטריה'], app(TenantContext::class)->run($this->shop->id, fn () => LoadedIndex::for($this->shop->id)['nochips'] ?? null));
    }
}
