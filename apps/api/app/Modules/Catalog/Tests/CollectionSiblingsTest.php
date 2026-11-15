<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadCollectionSiblings;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogSibling;
use App\Modules\Catalog\Support\PageSignals;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One model's versions a store sells as products of their own, as its collection pages link them: each card's
 * swatches (Jean Rachel: a leaf ring in citrine, in amethyst) and the list an app keeps in the page (Naot's
 * shsdProductHandles, which its swatches are drawn from by script).
 */
final class CollectionSiblingsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_ONE = <<<'HTML'
        <html><body>
        <header><nav><a href="/products/gift-card">Gift card</a></nav></header>
        <div class="grid">
          <div class="grid__item"><a href="/collections/all/products/leaf-ring-citrine">Leaf ring</a>
            <div class="grid__swatch__container">
              <a href="/products/leaf-ring-citrine" title="Citrine "></a><a href="/products/leaf-ring-amethyst" title="Amethyst"></a>
            </div>
          </div>
          <div class="grid__item"><a href="/products/plain-band">Plain band</a></div>
        </div>
        <script>var data = {"shsdProductHandles": [{"title": "Julius", "handle": "julius"}, {"title": "Julius - BA6 Soft Black", "handle": "julius-1"}]};</script>
        <section class="product-recommendations"><div class="card__swatches"><a href="/products/plain-band">x</a><a href="/products/julius">y</a></div></section>
        </body></html>
        HTML;

    private const PAGE_TWO = <<<'HTML'
        <html><body><div class="grid"><div class="grid__item"><a href="/products/leaf-ring-peridot">Leaf ring</a>
          <div class="swatches"><a href="/products/leaf-ring-peridot" title="Peridot"></a><a href="/products/leaf-ring-amethyst" title="Amethyst"></a></div>
        </div></div></body></html>
        HTML;

    /** past the last page: the menu's products again, nothing new */
    private const PAGE_THREE = '<html><body><header><nav><a href="/products/gift-card">Gift card</a></nav></header><div class="grid"></div></body></html>';

    public function test_a_collection_page_shows_its_cards_swatches_and_an_apps_lists(): void
    {
        $groups = PageSignals::siblingGroups(self::PAGE_ONE, 'https://jean.test/collections/all?page=1');

        $this->assertContains([['url' => '/products/julius', 'label' => 'Julius'], ['url' => '/products/julius-1', 'label' => 'Julius - BA6 Soft Black']], $groups, 'the list the page keeps in its data');
        $this->assertContains([['url' => '/products/leaf-ring-citrine', 'label' => 'Citrine'], ['url' => '/products/leaf-ring-amethyst', 'label' => 'Amethyst']], $groups, 'a card\'s swatches');
        $this->assertNotContains([['url' => '/products/plain-band', 'label' => 'x'], ['url' => '/products/julius', 'label' => 'y']], $groups, 'never the recommendations\' swatches');
    }

    public function test_the_catalogue_is_read_page_after_page_and_one_models_versions_are_kept_as_one_group(): void
    {
        Event::fake([CatalogProductsChanged::class]);
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['leaf-ring-citrine', 'leaf-ring-amethyst', 'leaf-ring-peridot', 'plain-band', 'julius', 'julius-1', 'gift-card'] as $handle) {
                CatalogProduct::query()->create([
                    'shop_id' => $shop->id, 'external_id' => $handle, 'type' => 'simple', 'status' => 'publish', 'title' => $handle,
                    'url' => 'https://jean.test/products/'.$handle, 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$handle, 'payload' => [],
                ]);
            }
        });
        $asked = [];
        Http::fake(function (Request $request) use (&$asked) {
            $asked[] = $request->url();

            return Http::response(match (true) {
                str_ends_with($request->url(), 'page=1') => self::PAGE_ONE,
                // products of the store this shop did not read (a demo reads some of a store): no reason to stop
                str_ends_with($request->url(), 'page=2') => '<html><body><div class="grid"><a href="/products/not-read-1">a</a><a href="/products/not-read-2">b</a></div></body></html>',
                str_ends_with($request->url(), 'page=3') => self::PAGE_TWO,
                default => self::PAGE_THREE,
            });
        });

        $run = (new ReadCollectionSiblings(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($shop->id);

        $this->assertSame(['https://jean.test/collections/all?page=1', 'https://jean.test/collections/all?page=2', 'https://jean.test/collections/all?page=3', 'https://jean.test/collections/all?page=4'], $asked, 'until a page shows nothing new');
        $this->assertSame(['pages' => 3, 'groups' => 2, 'products' => 5], array_intersect_key($run->output, array_flip(['pages', 'groups', 'products'])));
        $rows = app(TenantContext::class)->run($shop->id, fn () => CatalogSibling::query()->get()->mapWithKeys(fn (CatalogSibling $s): array => [CatalogProduct::query()->whereKey($s->product_id)->value('external_id') => [$s->group_key, $s->label]])->all());
        $this->assertSame($rows['leaf-ring-citrine'][0], $rows['leaf-ring-peridot'][0], 'one ring in three stones, though no page shows all three together');
        $this->assertSame($rows['leaf-ring-citrine'][0], $rows['leaf-ring-amethyst'][0]);
        $this->assertNotSame($rows['leaf-ring-citrine'][0], $rows['julius'][0]);
        $this->assertSame('Citrine', $rows['leaf-ring-citrine'][1], 'as its swatch names it');
        $this->assertArrayNotHasKey('plain-band', $rows, 'a model in one version');
        Event::assertDispatched(CatalogProductsChanged::class, fn (CatalogProductsChanged $e): bool => $e->shopId === $shop->id);

        // a store that does not answer: what was read before stays
        Http::fake(fn () => Http::response('', 503));
        (new ReadCollectionSiblings(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($shop->id);
        $this->assertSame(5, app(TenantContext::class)->run($shop->id, fn () => CatalogSibling::query()->count()));

        Features::override('catalog.collection_siblings', false, $shop->id);
        $this->assertNull(app(ReadCollectionSiblings::class)->handle($shop->id), 'off: no page is read');
    }
}
