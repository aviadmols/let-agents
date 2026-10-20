<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SamplePages;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\PageSignals;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A page of every kind is read before the rest of a scan, for what the feed does not carry. Ten
 * necklaces show nothing of what a ring asks: one of each category, type and set of choices.
 */
final class SamplePagesTest extends TestCase
{
    use RefreshDatabase;

    private const RING = <<<'HTML'
        <html><body>
        <header><nav><a href="/products/gift-card">Gift card</a></nav></header>
        <div class="product__page__siblings">
          <a href="/products/bold-ring-garnet" title="Garnet CZ"><img alt=""></a>
          <a href="https://jean.test/products/bold-ring-ruby"><span>Ruby CZ</span></a>
          <a href="/products/bold-ring">This one</a>
        </div>
        <form><input type="text" name="properties[Engraving]"><input type="hidden" name="properties[_bundle]"><input type="hidden" name="properties[Size]"></form>
        <div class="rc-widget">Subscribe and save</div>
        <section class="product-recommendations"><div class="card__swatches"><a href="/products/other-ring">Other</a></div></section>
        </body></html>
        HTML;

    public function test_a_page_shows_its_versions_its_subscription_and_its_own_choices(): void
    {
        $siblings = PageSignals::siblings(self::RING, 'https://jean.test/products/bold-ring');

        $this->assertSame([['url' => '/products/bold-ring-garnet', 'label' => 'Garnet CZ'], ['url' => '/products/bold-ring-ruby', 'label' => 'Ruby CZ']], $siblings, 'not itself, the menu, nor the recommendations\' swatches');
        $this->assertSame(['Recharge'], PageSignals::subscriptionApps(self::RING));
        $this->assertSame(['Engraving'], PageSignals::pageFields(self::RING), 'not a hidden or a private property');
        $this->assertSame(['plans' => 2, 'required' => true], PageSignals::shopifyPlans(['selling_plan_groups' => [[], []], 'requires_selling_plan' => true]));
        $list = '<div class="swatches">'.implode('', array_map(fn (int $i): string => '<a href="/products/p'.$i.'">p'.$i.'</a>', range(1, 21))).'</div>';
        $this->assertSame([], PageSignals::siblings($list, 'https://jean.test/products/y'), 'more than twenty is a list of products, not one product\'s versions');
    }

    public function test_one_page_of_every_kind_is_read_and_what_it_shows_is_reported(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://jean.test', 'access_token' => 'tok', 'platform' => 'shopify']));
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $necklaces = CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => 'n', 'name' => 'Necklaces', 'path' => ['Necklaces'], 'hash' => 'n', 'product_count' => 10]);
            $rings = CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => 'r', 'name' => 'Rings', 'path' => ['Rings'], 'hash' => 'r', 'product_count' => 1]);
            foreach (range(1, 10) as $i) {
                $this->product($shop, 'necklace-'.$i, ['attributes' => [['name' => 'Length', 'values' => ['40', '45'], 'used_for_variations' => true]]])->categories()->attach($necklaces->id);
            }
            $this->product($shop, 'bold-ring', ['attributes' => [['name' => 'Ring size', 'values' => ['6', '7'], 'used_for_variations' => true]]])->categories()->attach($rings->id);
        });
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), 'bold-ring.js') => Http::response(['selling_plan_groups' => [['name' => 'Monthly']], 'requires_selling_plan' => false]),
                str_ends_with($request->url(), '.js') => Http::response(['selling_plan_groups' => []]),
                str_ends_with($request->url(), 'bold-ring') => Http::response(self::RING),
                default => Http::response('<html><body><p>A necklace.</p></body></html>'),
            };
        });

        $run = (new SamplePages(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($shop->id);

        $this->assertSame(2, $run->output['sampled'], 'a necklace and the ring: every category and set of choices, not ten necklaces');
        $this->assertSame(['Bold ring' => ['Garnet CZ', 'Ruby CZ']], $run->output['siblings']['examples']);
        $this->assertSame(['Recharge'], $run->output['subscriptions']['apps']);
        $this->assertSame(1, $run->output['subscriptions']['products_with_plans']);
        $this->assertSame(['Engraving'], array_keys($run->output['page_fields']));
        $this->assertStringContainsString('2', (string) $run->summary());

        Features::override('catalog.sample_pages', false, $shop->id);
        $this->assertNull(app(SamplePages::class)->handle($shop->id), 'off: no page is read');
    }

    /** @param array<string, mixed> $payload */
    private function product(Shop $shop, string $handle, array $payload): CatalogProduct
    {
        return CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $handle, 'type' => 'variable', 'status' => 'publish', 'title' => ucfirst(str_replace('-', ' ', $handle)),
            'url' => 'https://jean.test/products/'.$handle, 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$handle, 'payload' => $payload,
        ]);
    }
}
