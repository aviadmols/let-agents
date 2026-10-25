<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product's kind, from what the store says of it: on Vans, whose categories say whom a product is for ("ילדים",
 * "גברים") and not what it is, a pink backpack's alike were kids' pink sneakers and a beanie. A Hebrew title opens
 * with what the product is ("תיק גב", "נעלי סניקרס"), and that is its kind before its categories.
 */
final class ProductKindsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hebrew_title_opening_names_the_kind_before_categories_of_whom_it_is_for(): void
    {
        $shop = Shop::factory()->create();
        $products = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $make = fn (string $id, string $title): CatalogProduct => CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 100,
                'payload' => ['categories' => [['id' => 'kids', 'name' => 'ילדים', 'path' => ['ילדים']]]],
            ]);

            return [
                'pink' => $make('1', 'תיק גב Old Skool Classic'),
                $make('2', 'תיק צד Lawler Tote'),
                $make('3', 'נעלי סניקרס לילדים Old Skool V'),
                $make('4', 'כובע גרב Vans Classic Cuff'),
                $make('5', 'נעלי סניקרס Authentic'),
                $make('6', 'Old Skool Backpack'),
            ];
        });

        $kinds = app(ProductKinds::class);
        app(TenantContext::class)->run($shop->id, function () use ($kinds, $products): void {
            $this->assertSame(['head:תיק'], $kinds->marks($products['pink']));
            $this->assertSame(['2'], $kinds->sameKind($products['pink'], ['3', '4', '2', '5']), 'a bag, not the kids\' sneakers or the beanie of its category');
            $this->assertSame(['category:ילדים'], $kinds->marks($products[4]), 'an English title names no kind: its categories do');
        });
    }
}
