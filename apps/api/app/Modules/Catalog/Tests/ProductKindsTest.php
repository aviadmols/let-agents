<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\CatalogProductKinds;
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
            $this->assertSame([], $kinds->marks($products[4]), 'an English title names no kind, nor does a category of whom it is for');
        });
    }

    public function test_a_category_of_whom_or_of_a_campaign_makes_no_bag_like_skate_shoes(): void
    {
        // Vans, 26.10: "נעליי סקייט Skate Rowan" (its yod doubled) was read by its categories, and a backpack shares "גברים"
        $shop = Shop::factory()->create();
        $category = fn (string ...$names): array => array_map(fn (string $n): array => ['id' => $n, 'name' => $n, 'path' => [$n]], $names);
        $products = app(TenantContext::class)->run($shop->id, function () use ($shop, $category): array {
            $make = fn (string $id, string $title, array $categories): CatalogProduct => CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 100,
                'payload' => ['categories' => $categories],
            ]);

            return [
                'rowan' => $make('1', 'נעליי סקייט Skate Rowan', $category('גברים', 'נעליים', 'כל מה שחם', 'SS26')),
                $make('2', 'נעלי סניקרס Old Skool', $category('גברים', 'נעליים')),
                $make('3', 'נעלי סניקרס Authentic', $category('גברים', 'נעליים')),
                $make('4', 'תיק גב Old Skool Classic', $category('גברים', 'תיקים', 'כל מה שחם', 'SS26')),
                $make('5', 'תיק צד Lawler', $category('גברים', 'תיקים')),
            ];
        });

        app(TenantContext::class)->run($shop->id, function () use ($products): void {
            $kinds = app(ProductKinds::class);
            $this->assertSame(['head:נעל'], $kinds->marks($products['rowan']), 'a doubled yod is one: shoes, by its title');
            $this->assertSame(['2', '3'], $kinds->sameKind($products['rowan'], ['4', '2', '5', '3']), 'never a bag');
        });
    }

    public function test_alike_products_are_for_the_same_people(): void
    {
        // Story: a man's shirt's alike were women's shirts; Vans: a baby's sneaker's alike should be children's.
        $shop = Shop::factory()->create();
        $products = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $make = fn (string $id, string $title, array $tags = []): CatalogProduct => CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 100,
                'payload' => ['tags' => $tags],
            ]);

            return [
                'man' => $make('1', 'חולצה מכופתרת לגברים Mateo'),
                $make('2', 'חולצה מכופתרת לנשים Kayla'),
                $make('3', 'חולצה מכופתרת Rains', ['_unisex']),
                $make('4', 'חולצה מכופתרת Shaw', ['ביגוד_גברים']),
                'baby' => $make('5', 'נעלי סניקרס לתינוקות Slip-On V'),
                $make('6', 'נעלי סניקרס לילדים Old Skool V'),
                $make('7', 'נעלי סניקרס Authentic', ['Women']),
                $make('8', 'Mens Classic Tee'),
                'tagged' => $make('9', 'אול סיינטס טישירט AYEDEE לגברים SS CREW', ['נשים']),
            ];
        });

        $kinds = app(ProductKinds::class);
        app(TenantContext::class)->run($shop->id, function () use ($kinds, $products): void {
            $this->assertSame(['3', '4'], $kinds->sameKind($products['man'], ['2', '3', '4']), 'never the woman\'s; the unisex and the men\'s tagged one, yes');
            $this->assertSame(['6'], $kinds->sameKind($products['baby'], ['6', '7']), 'a baby\'s sneaker is like a child\'s, never a woman\'s');
            $this->assertSame('men', CatalogProductKinds::audience($products[5]), 'in English too ("Mens Classic Tee")');
            $this->assertNull(CatalogProductKinds::audience($products[1]), 'unisex is for anyone');
            // Chozen, 28.10: a man's tee the store also tagged נשים came up for "חולצה אפורה לנשים"
            $this->assertSame('men', CatalogProductKinds::audience($products['tagged']), 'its title says whom, whatever its tags');
            $this->assertSame(['men'], $kinds->audiences($products['tagged']));
            $this->assertSame(['men'], $kinds->audiences($products[2]), 'a title that says none: its tags do');
        });
    }

    public function test_a_title_that_opens_with_its_brand_and_a_season_for_a_type_still_say_what_it_is(): void
    {
        // Chozen, 28.10: titles open with the brand in Hebrew ("נורתפייס נעלי בית") and every product is typed "חורף 26":
        // a slipper's alike were a fleece jacket and a bag.
        $shop = Shop::factory()->create();
        $products = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $make = fn (string $id, string $title, string $brand, string $type = 'חורף 26'): CatalogProduct => CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 100,
                'payload' => ['product_type' => $type, 'tags' => []],
            ]);

            return [
                'mules' => $make('1', 'נורתפייס נעלי בית THERMOBALL V TRACTION MULES לנשים', 'THE NORTH FACE'),
                $make('2', 'נורתפייס מעיל פליס CRAGMONT לנשים', 'THE NORTH FACE'),
                $make('3', 'נורתפייס נעלי בית THERMOBALL TRACTION לנשים', 'THE NORTH FACE'),
                $make('4', 'נורתפייס תיק גב BOREALIS', 'THE NORTH FACE'),
                $make('5', 'בראוני נעלי בובה לנשים', 'BROWNIE', 'NOT SALE'),
                $make('6', 'בראוני תיק צד לנשים', 'BROWNIE', 'NOT SALE'),
                $make('7', 'בראוני חגורה לנשים', 'BROWNIE', 'NOT SALE'),
                'jacket' => $make('8', "אול סיינטס ג'קט עור לנשים", 'ALL SAINTS'),
                $make('9', "אול סיינטס ג'קט ג'ינס לנשים", 'ALL SAINTS'),
                $make('10', 'אול סיינטס טישירט לנשים', 'ALL SAINTS'),
            ];
        });

        $kinds = app(ProductKinds::class);
        app(TenantContext::class)->run($shop->id, function () use ($kinds, $products): void {
            $this->assertSame(['head:נעל'], $kinds->marks($products['mules']), 'what follows the brand, not the brand or the season');
            $this->assertSame(['3', '5'], $kinds->sameKind($products['mules'], ['2', '3', '4', '5', '6']), "shoes, of any brand; never its brand's fleece or bag");
            $this->assertSame(['9'], $kinds->sameKind($products['jacket'], ['9', '10', '2']), 'a brand of two words, and a geresh in the kind');
        });

        // and a kind of two words, where enough are named by them: home slippers, never running shoes or flats
        $more = app(TenantContext::class)->run($shop->id, fn (): array => array_map(fn (array $p): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $p[0], 'type' => 'simple', 'status' => 'publish', 'title' => $p[1], 'brand' => $p[2],
            'url' => 'https://www.store.test/p/'.$p[0], 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$p[0], 'price' => 100, 'payload' => ['tags' => []],
        ]), [['11', 'הוקה נעלי ריצה Mach 7 לנשים', 'HOKA'], ['12', 'הוקה נעלי ריצה Clifton 11 לנשים', 'HOKA'], ['13', 'הוקה נעלי ריצה Kawana 3 לנשים', 'HOKA'], ['14', 'הוקה נעלי ריצה Challenger 8 לנשים', 'HOKA']]));
        app(TenantContext::class)->run($shop->id, function () use ($kinds, $products, $more): void {
            $this->assertSame(['12', '13', '14'], $kinds->sameKind($more[0], ['1', '3', '5', '12', '13', '14']), 'running shoes, of running shoes');
            $this->assertSame(['3', '5', '11'], $kinds->sameKind($products['mules'], ['3', '5', '11']), 'too few home slippers: any shoe');
        });
    }
}
