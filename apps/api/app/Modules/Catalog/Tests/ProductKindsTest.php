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
            // two kinds by their titles, though the store tags both alike: near, not like (Chozen: jeans beside trousers)
            $this->assertSame([], $kinds->sameKind($products['jacket'], ['10'], $near));
            $this->assertSame([], $near, 'a tee is no kind of jacket, nor near it: no mark in common');
            $this->assertSame(['12', '13', '14'], $kinds->sameKind($more[0], ['12', '13', '14', '1', '5'], $near));
            $this->assertSame(['1', '5'], $near, 'shoes that are not running shoes: maybe these');
        });
    }

    public function test_jeans_are_not_like_trousers_the_store_tags_alike_but_may_interest(): void
    {
        // Chozen, 28.10: "דזיגואל ג'ינס לגברים" had trousers and shorts for its alike, all tagged מכנסיים
        $shop = Shop::factory()->create();
        $p = app(TenantContext::class)->run($shop->id, fn (): array => array_map(fn (array $x): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $x[0], 'type' => 'simple', 'status' => 'publish', 'title' => $x[1], 'brand' => $x[2],
            'url' => 'https://www.store.test/p/'.$x[0], 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$x[0], 'price' => 100,
            'payload' => ['tags' => $x[3] ?? ['אופנה', 'מכנסיים', 'גברים']],
        ]), [
            ['1', "דזיגואל ג'ינס לגברים", 'DESIGUAL'], ['2', 'דזיגואל מכנסיים ארוכים לגברים', 'DESIGUAL'], ['3', 'דזיגואל חולצה לגברים', 'DESIGUAL', ['אופנה', 'חולצות', 'גברים']],
            ['4', 'אדידס מכנסיים ארוכים Germany לגברים', 'ADIDAS'], ['5', 'אדידס מכנסיים קצרים לגברים', 'ADIDAS'], ['6', 'אדידס סניקרס לגברים', 'ADIDAS', ['נעליים', 'גברים']], ['7', 'אדידס סניקרס SAMBA לגברים', 'ADIDAS', ['נעליים', 'גברים']],
            ['8', 'דזיגואל חולצה מכופתרת לגברים', 'DESIGUAL', ['אופנה', 'חולצות', 'גברים']], ['9', 'אדידס טישירט לגברים', 'ADIDAS', ['חולצות', 'גברים']],
        ]));

        app(TenantContext::class)->run($shop->id, function () use ($p): void {
            $same = app(ProductKinds::class)->sameKind($p[0], ['2', '4', '3', '5', '6'], $near);
            $this->assertSame([], $same, 'nothing is like the jeans');
            $this->assertSame(['2', '4', '5'], $near, 'the trousers may interest');
        });
    }

    public function test_a_shoe_the_store_tags_a_boot_is_like_its_boots_where_the_boots_are_filed_as_shoes(): void
    {
        // Chozen, 30.10: "פלדיום נעליים BAGGY לגברים" is tagged "מגפיים"; read as "נעליים" alone its boots were another kind,
        // and a sandal stood as its alike. The boots are tagged "נעליים" too: the store files boots under shoes.
        $shop = Shop::factory()->create();
        $p = app(TenantContext::class)->run($shop->id, fn (): array => array_map(fn (array $x): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $x[0], 'type' => 'simple', 'status' => 'publish', 'title' => $x[1], 'brand' => $x[2],
            'url' => 'https://www.store.test/p/'.$x[0], 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$x[0], 'price' => 100,
            'payload' => ['tags' => $x[3]],
        ]), [
            ['1', 'פלדיום נעליים BAGGY לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'מגפיים', 'נעליים']],
            ['2', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'מגפיים', 'נעליים']],
            ['3', 'פלדיום מגפיים PAMPA LITE לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'מגפיים', 'נעליים']],
            ['4', 'פלדיום מגפיים PAMPA ZIP לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'מגפיים', 'נעליים']],
            ['5', 'הוקה סנדלים Infini Hike לגברים', 'HOKA', ['גברים/יוניסקס', 'סנדלים', 'נעליים']],
            ['6', 'הוקה סנדלים Hopara לגברים', 'HOKA', ['גברים/יוניסקס', 'סנדלים', 'נעליים']],
            ['7', 'פלדיום נעליים PAMPA OX לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'סניקרס', 'נעליים']],
            ['8', 'פלדיום מגפיים BAGGY לנשים', 'PALLADIUM', ['נשים', 'מגפיים', 'נעליים']],
        ]));

        app(TenantContext::class)->run($shop->id, function (): void {
            $kinds = app(ProductKinds::class);
            $boot = CatalogProduct::query()->where('external_id', '1')->first();
            $this->assertSame(['2', '3', '4', '7'], $kinds->sameKind($boot, ['2', '5', '3', '6', '4', '7', '8']), 'the boots, and the other shoe; never the sandals, nor a woman\'s');
        });
    }

    public function test_a_books_title_opens_with_its_own_words_which_name_no_kind(): void
    {
        // Sela Meir, 28.10: "מפגש עם עצמי בגיהינום" had three alike ("ח"כ במשמרת לילה", "12 כללים לחיים") and the other
        // hostages' books as "maybe": "מפגש" and "חטוף" were read as two kinds, as "ג'ינס" and "מכנסיים" are.
        $shop = Shop::factory()->create();
        $p = app(TenantContext::class)->run($shop->id, fn (): array => array_map(fn (array $x): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $x[0], 'type' => 'simple', 'status' => 'publish', 'title' => $x[1], 'brand' => 'סלע מאיר',
            'url' => 'https://www.store.test/p/'.$x[0], 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$x[0], 'price' => 100,
            'payload' => ['product_type' => 'ספר', 'tags' => $x[2]],
        ]), [
            ['1', 'מפגש עם עצמי בגיהינום', ['ביוגרפיות', 'רבי מכר']], ['2', 'חטוף - אלי שרעבי', ['ביוגרפיות', 'רבי מכר']], ['3', 'ח"כ במשמרת לילה', ['ביוגרפיות']],
            ['4', 'מי שיש לו למה', ['ביוגרפיות']], ['5', 'ספרי השבעה באוקטובר', ['באנדל']], ['6', 'אבא בא', ['ביוגרפיות']],
        ]));

        app(TenantContext::class)->run($shop->id, function () use ($p): void {
            $this->assertTrue(app(ProductKinds::class)->isRead($p[0]));
            $same = app(ProductKinds::class)->sameKind($p[0], ['2', '3', '4', '6'], $near);
            $this->assertSame(['2', '3', '4', '6'], $same, 'all biographies, whatever word each title opens with');
            $this->assertSame([], $near);
            // a bundle filed under its imprint, not under the biographies: a book is of one kind with every book
            $this->assertSame(['1', '2', '6'], app(ProductKinds::class)->sameKind($p[4], ['1', '2', '6'], $near));
            $this->assertSame([], $near);
        });
    }

    public function test_a_shirt_is_never_a_sneakers_alike_by_whom_they_are_for(): void
    {
        // Chozen's demo, 28.10: where only Veja's titles said "סניקרס", "וג'ה סניקרס" was read as its name, and its
        // sneakers had no kind but their tags: "גברים/יוניסקס" made a shirt one's alike.
        $shop = Shop::factory()->create();
        $p = app(TenantContext::class)->run($shop->id, fn (): array => array_map(fn (array $x): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => $x[0], 'type' => 'simple', 'status' => 'publish', 'title' => $x[1], 'brand' => $x[2],
            'url' => 'https://www.store.test/p/'.$x[0], 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$x[0], 'price' => 100,
            'payload' => ['product_type' => 'חורף 26', 'tags' => $x[3]],
        ]), [
            ['1', "וג'ה סניקרס V-90 לגברים", 'VEJA', ['גברים/יוניסקס', 'החדשים', 'נעליים']],
            ['2', "וג'ה סניקרס CAMPO לגברים", 'VEJA', ['גברים/יוניסקס', 'נעליים']],
            ['3', "וג'ה סניקרס RIO BRANCO לגברים", 'VEJA', ['גברים/יוניסקס', 'נעליים']],
            ['4', "סקוץ' אנד סודה חולצה מכופתרת לגברים", 'SCOTCH & SODA', ['גברים/יוניסקס', 'חולצות']],
            ['5', "סקוץ' אנד סודה סווטשירט לגברים", 'SCOTCH & SODA', ['גברים/יוניסקס', 'סווטשירטים']],
            ['6', "סקוץ' אנד סודה מכנסיים לגברים", 'SCOTCH & SODA', ['גברים/יוניסקס', 'מכנסיים']],
            ['7', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', ['גברים/יוניסקס', 'נעליים']],
        ]));

        app(TenantContext::class)->run($shop->id, function () use ($p): void {
            $kinds = app(ProductKinds::class);
            $this->assertSame(['head:סניקרס'], $kinds->marks($p[0]), "its name is וג'ה; what its titles say after it is the kind");
            $same = $kinds->sameKind($p[0], ['2', '4', '5', '3', '6', '7'], $near);
            $this->assertSame(['2', '3'], $same);
            $this->assertNotContains('4', [...$same, ...$near], 'never a shirt, not even as maybe');
        });
    }
}
