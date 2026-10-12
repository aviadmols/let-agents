<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Without relations computed at night, the page's other sizes come from the family code read,
 * and its alternatives from the index's nearest products of the same type, in stock.
 */
final class IndexSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var array<string, string> external id => product id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->run($this->shop->id, function (): void {
            // 1 is the page; 2 and 3 are pendants near it; 4 is its other size; 5 is a ring, nearest of all; 6 is out of stock.
            foreach (['1' => ['pendant', 'a', true, false], '2' => ['pendant', null, true, true], '3' => ['pendant', null, true, false],
                '4' => ['pendant', 'a', true, false], '5' => ['ring', null, true, false], '6' => ['pendant', null, false, false]] as $id => [$type, $family, $inStock, $sale]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "מוצר {$id}",
                    'url' => "https://store.test/p/{$id}", 'image_url' => "https://store.test/p/{$id}.jpg", 'price' => 100, 'in_stock' => $inStock, 'on_sale' => $sale, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                $this->ids[$id] = $product->id;
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => FactKind::Type, 'key' => 'type', 'value_text' => $type,
                    'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "t{$id}"]);
                EnrichmentCodeReading::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'family_key' => $family, 'reading' => [], 'reading_hash' => "r{$id}", 'read_at' => now()]);
            }
        });
        $ids = $this->ids;
        $this->app->instance(SemanticSearch::class, new class($ids) implements SemanticSearch
        {
            public function __construct(private readonly array $ids) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                return array_map(fn (string $id): array => ['source' => 'product', 'source_id' => $this->ids[$id], 'external_id' => $id, 'title' => '', 'similarity' => 0.9], ['5', '6', '2', '4', '3']);
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return [];
            }

            public function lookAlike(string $productId, int $limit): array
            {
                return [];
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
                return true;
            }

            public function ready(): bool
            {
                return true;
            }
        });
        Features::override('widget.on_products', true, $this->shop->id);
    }

    public function test_without_relations_the_sections_come_from_the_indexes(): void
    {
        $sections = collect(app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['sections'])->keyBy('candidate');

        $this->assertSame(['4'], array_column($sections['family']['products'], 'id'), 'its other size, from the family code read');
        $this->assertSame(['2', '3'], array_column($sections['alternatives']['products'], 'id'), 'the nearest pendants in stock, in the index\'s order; not the ring, not its own size');
        $this->assertSame(['2'], array_column($sections['on_sale']['products'], 'id'));
    }
}
