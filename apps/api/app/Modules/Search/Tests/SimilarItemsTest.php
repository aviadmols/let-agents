<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Similar items" on a result: by picture where the pictures are scanned, by meaning otherwise,
 * from stored vectors only; never the product itself, what is in stock first. The drawer and its
 * side are the shop's choice, read by the box with the rest of its settings.
 */
final class SimilarItemsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    public bool $pictures = true;

    /** @var array<string, int> which kind of nearness was asked, how many times */
    public array $asked = ['picture' => 0, 'text' => 0];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['301' => ['Veja Panenka', true], '302' => ['Veja Volley', false], '303' => ['Veja Campo', true]] as $id => [$title, $stock]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish',
                    'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'image_url' => null,
                    'price' => '419.94', 'currency' => 'ILS', 'in_stock' => $stock, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $test = $this;
        $this->app->instance(SemanticSearch::class, new class($test) implements SemanticSearch
        {
            public function __construct(private readonly SimilarItemsTest $test) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                $this->test->asked['text']++;

                return [['source' => 'product', 'source_id' => 'x', 'external_id' => '303', 'title' => 'Veja Campo', 'similarity' => 0.8]];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return [];
            }

            public function lookAlike(string $productId, int $limit): array
            {
                $this->test->asked['picture']++;

                return [
                    ['product_id' => 'a', 'external_id' => '301', 'title' => 'Veja Panenka', 'similarity' => 1.0],
                    ['product_id' => 'b', 'external_id' => '302', 'title' => 'Veja Volley', 'similarity' => 0.9],
                    ['product_id' => 'c', 'external_id' => '303', 'title' => 'Veja Campo', 'similarity' => 0.8],
                ];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return [];
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return $this->test->pictures;
            }

            public function ready(): bool
            {
                return true;
            }
        });
    }

    public function test_similar_items_come_by_picture_in_stock_first_and_never_the_product_itself(): void
    {
        $products = $this->similar('301')->assertOk()->json('products');

        $this->assertSame(['303', '302'], array_column($products, 'external_id'), 'not the product itself, and what is in stock first');
        $this->assertSame(['picture' => 1, 'text' => 0], $this->asked);
    }

    public function test_without_scanned_pictures_they_come_by_meaning(): void
    {
        $this->pictures = false;

        $this->assertSame(['303'], array_column($this->similar('301')->json('products'), 'external_id'));
        $this->assertSame(['picture' => 0, 'text' => 1], $this->asked);
    }

    public function test_the_shop_chooses_the_drawer_its_side_and_whether_similar_items_show(): void
    {
        Settings::set('search.results', 'drawer', $this->shop->id);
        Settings::set('search.drawer_side', 'end', $this->shop->id);

        $config = $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->assertOk()->json('config');
        $this->assertSame(['drawer', 'end', true], [$config['results'], $config['drawerSide'], $config['similar']]);

        Features::override('search.similar', false, $this->shop->id);
        $this->similar('301')->assertNotFound();
        $this->similar('bad id!')->assertNotFound();
    }

    private function similar(string $id): TestResponse
    {
        return $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id='.rawurlencode($id));
    }
}
