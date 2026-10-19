<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadPageChoices;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\StoreRest;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Tenancy\Models\Shop;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A store's site that answers with server errors is left alone: our reading never tips a weak server over. */
final class StoreRestTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_server_errors_in_a_row_stop_the_reading_and_rest_the_site(): void
    {
        $shop = Shop::factory()->create();
        Features::override('catalog.page_sizes', true, $shop->id);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (range(1, 8) as $i) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => "p{$i}", 'url' => "https://weak.test/p/{$i}", 'price' => 10, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$i}", 'payload' => []]);
            }
        });
        $asked = 0;
        Http::fake(function () use (&$asked) {
            $asked++;

            return Http::response('Bad Gateway', 502);
        });
        $read = new ReadPageChoices(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null);

        $read->handle($shop->id);

        $this->assertSame(StoreRest::TIMES, $asked, 'stopped after three, not all eight');
        $this->assertTrue(StoreRest::resting($shop->id));

        $read->handle($shop->id);
        $this->assertSame(StoreRest::TIMES, $asked, 'resting: nothing more is sent');
        $this->assertFalse(StoreRest::struggling(new Response(new Psr7Response(404))), 'a missing page is no sign of trouble');
    }
}
