<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Support\KnowledgeOfProducts;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The products on the Store knowledge screen (the redesign, 11.2026): the counts, a row to pick from, and one product
 * with what was understood of it and where from (the title, the page, the photo, the team), and what its photo shows.
 */
final class KnowledgeOfProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_product_with_what_was_understood_and_where_from(): void
    {
        $shop = Shop::factory()->create(['currency' => 'ILS']);

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $dress = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'title' => 'שמלת פשתן לבנה', 'type' => 'simple', 'status' => 'publish', 'purchasable' => true, 'in_stock' => true, 'hash' => 'h1', 'payload' => [], 'price' => 128, 'image_url' => 'https://x.test/dress.jpg', 'url' => 'https://x.test/dress']);
            CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '2', 'title' => 'כובע קש', 'type' => 'simple', 'status' => 'publish', 'purchasable' => true, 'in_stock' => true, 'hash' => 'h2', 'payload' => []]);
            foreach ([['פשתן', 'title', FactOrigin::Code], ['לבן', 'image', FactOrigin::Code], ['קיץ', 'description', FactOrigin::Code], ['אורחת בחתונה', 'reading', FactOrigin::Code], ['מתנה', 'title', FactOrigin::Person]] as [$label, $source, $origin]) {
                EnrichmentFact::query()->create(['shop_id' => $shop->id, 'product_id' => $dress->id, 'kind' => FactKind::Tag, 'key' => 'k'.md5($label), 'value_text' => $label, 'quote' => $label,
                    'origin' => $origin, 'status' => FactStatus::Approved, 'input_hash' => md5($label), 'evidence' => ['source' => $source]]);
            }
            RetrievalImage::query()->create(['product_id' => $dress->id, 'external_id' => '1', 'title' => 'x', 'image_url' => 'https://x.test/dress.jpg', 'url_hash' => 'a', 'caption' => 'שמלת מידי לבנה עם שרוולים קצרים.', 'captioned_at' => now()]);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => CatalogContent::PAGE, 'external_id' => 'p1', 'title' => 'משלוחים', 'hash' => 'c1']);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => CatalogContent::POST, 'external_id' => 'b1', 'title' => 'איך בוחרים שמלה', 'hash' => 'c2']);

            $knowledge = app(KnowledgeOfProducts::class);
            $this->assertSame(['products' => 2, 'photos' => 1, 'pages' => 1, 'posts' => 1, 'read_at' => null], $knowledge->numbers($shop));
            $this->assertSame(['שמלת פשתן לבנה', 'כובע קש'], array_column($knowledge->strip($shop, '', 12), 'title'), 'the product something was understood of opens first');
            $this->assertSame([(string) $dress->id], array_column($knowledge->strip($shop, 'פשתן', 12), 'id'));

            $product = $knowledge->product($shop, (string) $dress->id);
            $this->assertSame([
                ['label' => 'פשתן', 'from' => 'title'], ['label' => 'לבן', 'from' => 'photo'], ['label' => 'קיץ', 'from' => 'page'],
                ['label' => 'אורחת בחתונה', 'from' => 'understood'], ['label' => 'מתנה', 'from' => 'team'],
            ], $product['tags']);
            $this->assertSame('שמלת מידי לבנה עם שרוולים קצרים.', $product['saw']);
            $this->assertStringContainsString('128', (string) $product['price']);
            $this->assertNull($knowledge->product($shop, 'nope'));
        });
    }

    public function test_the_products_tab_shows_the_open_product_on_the_screen(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create(['locale' => 'en']);
        $merchant->attachShop($shop);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $hat = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '2', 'title' => 'Wide-brim straw hat', 'type' => 'simple', 'status' => 'publish', 'purchasable' => true, 'in_stock' => true, 'hash' => 'h2', 'payload' => []]);
            EnrichmentFact::query()->create(['shop_id' => $shop->id, 'product_id' => $hat->id, 'kind' => FactKind::Tag, 'key' => 'kstraw', 'value_text' => 'straw', 'quote' => 'straw',
                'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'x', 'evidence' => ['source' => 'title']]);
        });

        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/about-store?tab=products")
            ->assertOk()
            ->assertSee('Teach Lets something')
            ->assertSee('What we understood')
            ->assertSee('Wide-brim straw hat')
            ->assertSee('from the title')
            ->assertSee('What Lets knows');
    }
}
