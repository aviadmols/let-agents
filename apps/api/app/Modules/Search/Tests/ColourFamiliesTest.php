<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogSibling;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\Colours;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A model the store sells as a product a colour (Chozen, 28.10: "פלדיום מגפיים PAMPA HI לגברים" in beige and in brown)
 * is one family, each of its cards showing the others' colours; and a product's colour is what the store says it is,
 * its title or "בצבע" in its description, before the colour of a detail its picture names: a beige boot is no blue boot.
 */
final class ColourFamiliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_what_a_picture_says_a_product_is_finds_it_first_where_its_title_is_a_models_name(): void
    {
        // Naot QA, 16.11: "wedge" led with flat sandals whose pictures had "a low wedge" among their details
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => ['Abbie', ['sandal', 'ankle strap', 'cork footbed', 'low wedge']], '2' => ['Acoustic', ['sandals', 'crossover strap', 'low wedge']],
                '3' => ['Dynasty', ['sandal', 'wedge sandal', 'two straps']], '4' => ['טבעת זהב', ['טבעת', 'wedge']]] as $id => [$title, $words]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $id, 'title' => $title,
                    'image_url' => "https://store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', (string) $id), 'caption_words' => $words]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $index = app(TenantContext::class)->run($shop->id, fn () => LoadedIndex::for($shop->id));
        $items = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'], null, 'id');

        $this->assertSame('p:3', HebrewSearch::search($index['engine'], 'wedge')[0]['id'] ?? null, 'the wedge sandal before the sandals with a low wedge');
        $this->assertArrayNotHasKey('tg', $items['p:4'], 'a title that says what it is gets no tag from its picture');

        // a store whose search goes by meaning (tours, books): a picture says nothing of what is sold
        Settings::set('search.similar_by', 'meaning', $shop->id);
        app(BuildSearchIndex::class)->handle($shop->id);
        $items = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'], null, 'id');
        $this->assertArrayNotHasKey('tg', $items['p:3']);
    }

    public function test_trousers_of_the_colour_asked_are_found_by_what_their_picture_says_they_are_and_the_nearest_colour_comes_first(): void
    {
        // Miss Nori, 16.11: "מכנסיים אדומים" said "nothing exactly" and showed every trouser, brown and black first: the red
        // shorts ("שורטס הארט | בז/אדום") were no trousers by their title or their tags (months, campaigns)
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        $add = fn (string $id, string $title, array $words, array $attributes = []) => app(TenantContext::class)->run($shop->id, function () use ($shop, $id, $title, $words, $attributes): void {
            $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => ['נובמבר', 'אוקטובר'], 'attributes' => $attributes]]);
            RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $title,
                'image_url' => "https://store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words]);
        });
        $add('1', 'מכנסי בלון גומי | חום', ['מכנסיים', 'מכנסי בלון', 'חום']);
        $add('2', 'מכנסי טובי גזרה מחויטת | שחור', ['מכנסיים', 'גזרה מחויטת', 'שחור']);
        $add('3', 'מכנסי כריסטה גזרה ישרה | לבן', ['מכנסיים', 'גזרה ישרה', 'לבן']);
        $add('4', 'מכנסי מיה בד פייזלי | בורדו/תכלת', ['מכנסיים', 'בד פייזלי', 'בורדו']);
        // a red top the store says goes with trousers: it holds both words, and is no trouser
        $add('5', 'חולצת לאנה בשילוב קשירות | אדום', ['חולצה', 'קשירות', 'אדום'], [['name' => 'מתאים עם', 'values' => ['מכנסיים לבנים']]]);
        $add('6', 'חולצת בייסיק | שחור', ['חולצה', 'שחור']);
        foreach (['11' => 'שמלת סאטן | ירוק', '12' => 'שמלת מקסי | לבן', '13' => 'חצאית מיני | שחור', '14' => 'חצאית מידי | חום', '15' => 'סריג אוברסייז | אפור'] as $id => $title) {
            $add((string) $id, $title, [explode(' ', $title)[0]]);
        }
        $search = function (string $q) use ($shop): array {
            app(BuildSearchIndex::class)->handle($shop->id);
            LoadedIndex::forget();

            return app(SearchCatalog::class)->handle($shop->id, $q, count: false);
        };

        // none red: the trousers without it, the burgundy ones first, never the red shirt
        $found = $search('מכנסיים אדומים');
        $ids = array_column($found['groups']['product'], 'external_id');
        $this->assertSame(['אדומים'], $found['loosened']['left'] ?? null);
        $this->assertSame('4', $ids[0], 'burgundy is red: nearest the colour asked');
        $this->assertSame('p:4', $found['ids'][0]);
        $this->assertSame(array_values(array_unique($found['ids'])), $found['ids'], 'each product once, in the ids the drawer shows as in the shown ones');
        $this->assertCount(4, $found['ids'], 'the four trousers');
        $this->assertNotContains('5', $ids, 'a red shirt is no trouser');

        // red shorts: trousers, by what their picture says they are, and what is shown
        $add('7', 'שורטס הארט סטרייפס סיומת קיפול | בז/אדום', ['שורטס', 'מכנסיים קצרים', 'פסים אדום']);
        $found = $search('מכנסיים אדומים');
        $this->assertArrayNotHasKey('loosened', $found);
        $this->assertSame(['7'], array_column($found['groups']['product'], 'external_id'));

        // a set of a sweatshirt and wide trousers, as its picture lists its pieces: trousers too (Miss Nori, 16.11)
        $add('8', 'חליפת ווידלג וסוויטשירט עם רוכסן | אדום', ['חליפת ספורט', 'ווידלג', 'אדום', 'סוויטשירט', 'מכנסיים רחבים', 'רוכסן']);
        $found = $search('מכנסיים אדומים');
        $this->assertEqualsCanonicalizing(['7', '8'], array_column($found['groups']['product'], 'external_id'));
        $this->assertNotContains('5', array_column($found['groups']['product'], 'external_id'), 'still no red top, whatever its picture shows beside it');
    }

    public function test_a_pendant_photographed_on_a_chain_is_no_necklace_unless_its_title_says_it_comes_with_one(): void
    {
        // Rocks: 364 of its 372 pendants' words say "שרשרת" (the chain they are photographed on); only a title that names a
        // piece of what its picture shows (a set, "תליון עם שרשרת") makes those pieces kinds of it
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => ['שרשרת זהב דקה', ['שרשרת', 'זהב צהוב', 'חוליות דקות']], '2' => ['שרשרת חוליות זהב', ['שרשרת', 'חוליות', 'זהב צהוב']],
                '3' => ['תליון לב זהב', ['תליון', 'לב', 'שרשרת דקה', 'זהב צהוב']], '4' => ['תליון כוכב עם שרשרת', ['תליון', 'כוכב', 'שרשרת דקה', 'זהב צהוב']],
                '5' => ['טבעת זהב', ['טבעת', 'זהב צהוב']], '6' => ['טבעת יהלום', ['טבעת', 'יהלום']], '7' => ['טבעת חותם', ['טבעת', 'חותם']], '8' => ['עגילי זהב', ['עגילים', 'זהב']],
                '9' => ['עגילי חישוק', ['עגילים', 'חישוק']], '10' => ['צמיד זהב', ['צמיד', 'זהב']]] as $id => [$title, $words]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $id, 'title' => $title,
                    'image_url' => "https://store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', (string) $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        $found = array_column(app(SearchCatalog::class)->handle($shop->id, 'שרשרת זהב', count: false)['groups']['product'], 'external_id');

        $this->assertContains('1', $found);
        $this->assertContains('4', $found, 'a pendant whose title says it comes with a chain');
        $this->assertNotContains('3', $found, 'a pendant photographed on a chain is no necklace');
    }

    public function test_one_brand_under_its_names_is_no_brand_filter_two_brands_are(): void
    {
        // Naot QA, 16.11: "Naot Footwear Australia" and "Naot Footwear Industries", one brand, a filter that narrows nothing
        foreach ([[['Naot Footwear Australia', 'Naot Footwear Industries'], false], [['Stanley', 'Makita'], true]] as [$brands, $filter]) {
            LoadedIndex::forget();
            $shop = Shop::factory()->create(['content_locale' => 'en']);
            app(TenantContext::class)->run($shop->id, function () use ($shop, $brands): void {
                foreach (range(1, 6) as $i) {
                    CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => 'Model '.$i,
                        'brand' => $brands[$i % 2], 'url' => "https://store.test/p/{$i}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$i}", 'payload' => []]);
                }
            });
            app(BuildSearchIndex::class)->handle($shop->id);
            $facets = (array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['facets'];

            $this->assertSame($filter, collect($facets)->contains('k', 'brand'), implode(' / ', $brands));
        }
    }

    public function test_a_word_that_only_starts_like_a_colour_is_no_colour(): void
    {
        // Rocks, 2.11: a spiral halo read as a sapphire's blue, a rectangular stone as white.
        foreach (['מלבנית', 'מלבניות', 'ומלבני', 'מלבן', 'סגלגלה', 'ספירלה', 'וספירלת', 'ספירלי', 'מרובים', 'רוזטה', 'לבנדר', 'לבנייה', 'שלבניה', 'הלבנת', 'להלבנה'] as $word) {
            $this->assertSame([], Colours::mentions($word), $word);
        }
        foreach (['לבן' => 'white', 'לבנה' => 'white', 'הלבנה' => 'white', 'ולבנים' => 'white', 'סגול' => 'purple', 'ספיר' => 'blue', 'וספיר' => 'blue', 'ספירים' => 'blue', 'רובי' => 'red', 'ורובי' => 'red', 'רוז' => 'gold_rose'] as $word => $family) {
            $this->assertSame($family, Colours::mentions($word)[0]['family'] ?? null, $word);
        }
    }

    public function test_one_model_in_colours_is_a_family_and_its_colours_are_the_stores_words(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                ['1', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'נעליים PAMPA לגברים בצבע בז\' עם סגירת שרוכים בצבע בז\'.'],
                ['2', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'פלדיום מגפיים PAMPA HI לגברים בצבע חום, עשויים מבד קנבס.'],
                ['3', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'מגפיים בצבע שחור.'],
                ['4', 'אדידס סניקרס SAMBA OG לגברים', 'ADIDAS', 'סניקרס בצבע כחול כהה עם פסי המותג בצבע שמנת.'],
                ['5', 'פלדיום מגפיים BAGGY לגברים', 'PALLADIUM', 'מגפיים בצבע שחור.'],
            ] as [$id, $title, $brand, $text]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                    'url' => "https://store.test/p/{$id}", 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
                    'payload' => ['description' => '<p>'.$text.'</p>', 'tags' => ['נעליים']]]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $items = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'], null, 'id');

        $this->assertSame(['בז'], $items['p:1']['mc'], 'beige, as the store says it, though no colour word in its title');
        $this->assertSame(['חומ'], $items['p:2']['mc']);
        $this->assertSame(['כחול'], $items['p:4']['mc'], 'its first colour, never its stripes\'');
        $this->assertNotNull($items['p:1']['cf'] ?? null);
        $this->assertSame($items['p:1']['cf'], $items['p:2']['cf']);
        $this->assertSame($items['p:1']['cf'], $items['p:3']['cf'], 'one model in three colours');
        $this->assertArrayNotHasKey('cf', $items['p:5'], 'another model of the brand');
        $this->assertArrayNotHasKey('cf', $items['p:4'], 'a model in one colour');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $items['p:1']['cf'], 'a family read from colour words: a card each, as before');
    }

    public function test_versions_the_stores_pages_link_are_one_family_whatever_colour_is_read_of_them(): void
    {
        // Jean Rachel, 16.11: a leaf ring in citrine, in amethyst, products of their own named apart, no colour filter;
        // its collection pages link them (ReadCollectionSiblings)
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => '14K Gold Citrine Leaf Ring', '2' => '14K Gold Amethyst Leaf Ring', '3' => 'Sterling Silver Crosshatch Band'] as $id => $title) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/products/p{$id}", 'price' => 900, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                if ((string) $id !== '3') {
                    CatalogSibling::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'group_key' => 'leaf', 'label' => (string) $id === '1' ? 'Citrine' : 'Amethyst']);
                }
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $items = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'], null, 'id');

        $this->assertNotNull($items['p:1']['cf'] ?? null);
        $this->assertSame($items['p:1']['cf'], $items['p:2']['cf'] ?? null, 'the store linking them is word enough');
        $this->assertStringStartsWith('s', (string) $items['p:1']['cf'], 'linked by the store: one card in the drawer');
        $this->assertSame(['Citrine', 'Amethyst'], [$items['p:1']['cl'] ?? null, $items['p:2']['cl'] ?? null], 'each named as its swatch is');
        $this->assertArrayNotHasKey('cf', $items['p:3']);
    }

    public function test_a_models_name_and_its_colour_after_a_dash_is_a_family_coloured_by_the_stores_colour_filter(): void
    {
        // Naot, 15.11: "Julius", "Julius - BA6 Soft Black", "Julius - 739 Buffallo" were three products and no family (no
        // Hebrew colour word, two brand names), and "Kayla" twice was two cards no one could tell apart
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                ['1', 'Julius', 'Naot Footwear Australia', 'Black'],
                ['2', 'Julius - BA6 Soft Black', 'Naot Footwear Industries', 'Black'],
                ['3', 'Julius - 739 Buffallo', 'Naot Footwear Australia', 'Brown'],
                ['4', 'Kayla', 'Naot Footwear Australia', 'Black'],
                ['5', 'Kayla', 'Naot Footwear Australia', 'Beige'],
                ['6', 'Martin', 'Naot Footwear Australia', 'Black'],
            ] as [$id, $title, $brand, $colour]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'variable', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                    'url' => "https://store.test/p/{$id}", 'price' => 200, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'variations_count' => 1,
                    'payload' => ['tags' => [(int) $id <= 3 ? 'shsdchildproduct' : 'shsdparentproduct', (int) $id % 2 ? 'SHOP BY OCCASION' : '#Naotic Favorites', (int) $id <= 3 ? 'Sandals' : 'Shoes',
                        (int) $id % 2 ? 'preorder' : 'naotgivesback', (int) $id % 2 ? 'FINALSALE' : 'Gift Guide > For Her'], 'variations' => [[
                            'external_id' => $id.'1', 'status' => 'publish', 'attributes' => [['key' => 'color', 'name' => 'Color', 'value' => $colour], ['key' => 'size', 'name' => 'Size', 'value' => '40']],
                            'price' => ['price' => '200'], 'stock' => ['in_stock' => true], 'purchasable' => true,
                        ]]]]);
                app(BuildProductVariants::class)->product($product);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $data = (array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true);
        $items = array_column($data['items'], null, 'id');

        $this->assertNotNull($items['p:1']['cf'] ?? null, 'a model in black and brown');
        $this->assertSame([$items['p:1']['cf'], $items['p:1']['cf']], [$items['p:2']['cf'] ?? null, $items['p:3']['cf'] ?? null], 'its colours after a dash, whichever brand name');
        $this->assertNotNull($items['p:4']['cf'] ?? null);
        $this->assertSame($items['p:4']['cf'], $items['p:5']['cf'] ?? null, 'one name in two colours, one family: one card in the drawer');
        $this->assertArrayNotHasKey('cf', $items['p:6'], 'a model in one colour');
        $this->assertStringStartsWith('m', (string) $items['p:1']['cf'], 'a model by its name: one card in the drawer');

        $tags = collect($data['facets'])->firstWhere('k', 'store_tags');
        $this->assertSame(['Sandals', 'Shoes'], array_column((array) ($tags['v'] ?? []), 'l'), 'never an app\'s tag, a menu\'s heading or a hashtag');
    }

    public function test_one_model_in_colours_shares_what_its_pictures_say_of_the_model_never_a_colour_or_a_print(): void
    {
        // Me369 QA, 18.11: the green Martha camisole dress said "sleeveless", the white one only "thin straps", and "white
        // sleeveless dress" missed the white one.
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                '1' => [['maxi dress', 'green', 'dark green', 'sleeveless', 'thin straps', 'swirl print'], ['name' => 'swirl', 'covers' => 'all']],
                '2' => [['maxi dress', 'cream', 'off-white', 'thin straps', 'spaghetti straps'], ['covers' => 'none']],
            ] as $id => [$words, $pattern]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'Martha Camisole Dress',
                    'url' => "https://store.test/p/{$id}", 'price' => 305, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $id, 'title' => $product->title,
                    'image_url' => "https://store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', (string) $id), 'caption_words' => $words, 'caption_pattern' => $pattern]);
                CatalogSibling::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'group_key' => 'martha', 'label' => $id === '1' ? 'Green' : 'White']);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $items = collect(json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'])->keyBy('id');

        $white = ' '.mb_strtolower((string) $items['p:2']['kw']).' ';
        $this->assertStringContainsString(' sleeveless ', $white, 'what the green one\'s picture says of the model, the white one is too');
        $this->assertStringNotContainsString('green', $white, 'never the other\'s colour');
        $this->assertStringNotContainsString('swirl', $white, 'nor its print');
        $this->assertStringContainsString('spaghetti straps', mb_strtolower((string) $items['p:1']['kw']), 'and the other way round');
    }

    public function test_a_product_whose_variants_name_no_colour_is_filtered_by_the_main_colour_its_picture_shows(): void
    {
        // Me369, 18.11: 5 of 1,185 variants named a colour, the Colour filter held "Pink" alone, and "jackets white" brought
        // brown and grey jackets. The first colour the description of its picture lists, a colour alone, is the product's.
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        Settings::set('search.photo_match', 'look', $shop->id);
        $pictures = [
            '1' => ['Sloane Utility Jacket', ['jacket', 'white', 'off-white', 'embroidery']],
            '2' => ['Briann EMB Denim', ['denim jacket', 'shirt jacket', 'white', 'denim']],
            '3' => ['Kiara Belted Jacket', ['jacket', 'gold buttons', 'brown', 'rust brown']],
            '4' => ['Della Cropped Jacket', ['jacket', 'charcoal grey', 'cream']],
            '5' => ['Plain Tote', ['tote bag', 'canvas']],
            '6' => ['Kinsley Emb Black Wool Jacket', ['jacket', 'charcoal', 'black', 'wool']],
        ];
        app(TenantContext::class)->run($shop->id, function () use ($shop, $pictures): void {
            foreach ($pictures as $id => [$title, $words]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $id, 'title' => $title,
                    'image_url' => "https://store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', (string) $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $data = (array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true);

        $at = collect($data['facets'])->search(fn (array $f): bool => $f['k'] === BuildSearchIndex::VARIANT_COLOUR);
        $this->assertNotFalse($at, 'a Colour filter, from the pictures');
        $codes = fn (string $id): array => (array) (collect($data['items'])->firstWhere('id', 'p:'.$id)['f'] ?? []);
        $value = fn (string $key): int => $at * 1000 + (int) collect($data['facets'][$at]['v'])->search(fn (array $v): bool => $v['k'] === $key);
        $this->assertContains($value('white'), $codes('1'));
        $this->assertContains($value('white'), $codes('2'), '"denim jacket" is a kind, not the colour blue');
        $this->assertContains($value('brown'), $codes('3'), 'the gold of its buttons is no colour of the jacket');
        $this->assertContains($value('grey'), $codes('4'));
        $this->assertContains($value('black'), $codes('6'), 'its name says black: black, whatever its picture says first');
        $this->assertContains($value('grey'), $codes('6'), 'and the charcoal its picture shows');
        $this->assertSame([], array_values(array_filter($codes('5'), fn (int $c): bool => intdiv($c, 1000) === $at)), 'a picture that names no colour gives none');
    }
}
