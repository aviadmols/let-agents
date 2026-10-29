<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Enrichment\Contracts\ProductMeaning;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Merchant\Pages\ScanSummary;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Sources\ProductDocuments;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The scan summary, as the designer's panel lays it out: the numbers, the products with their state,
 * and the open product with its tags (each with where it came from), its spec and its picture; the
 * team removes a tag, adds one, and scans the product again.
 */
final class ScanSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{string, string, string}> */
    public array $rereads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        $test = $this;
        $this->app->instance(RereadsPages::class, new class($test) implements RereadsPages
        {
            public function __construct(private readonly ScanSummaryTest $test) {}

            public function reread(string $shopId, string $type, string $externalId): array
            {
                $this->test->rereads[] = [$shopId, $type, $externalId];

                return ['written' => 3, 'kinds' => [], 'notes' => []];
            }
        });

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_the_product_its_tags_with_their_source_and_its_picture(): void
    {
        $mdf = $this->product('15377', 'פלטת לביד MDF במבחר גדלים');
        $disc = $this->product('30222', 'דיסק סקוץ׳ למכונת פוליש');
        $this->fact($mdf, FactKind::Category, 'פלטה / לביד / MDF', 'category');
        $this->fact($mdf, FactKind::Tag, 'מרקם סיבי', 'image');
        RetrievalImage::query()->create(['product_id' => $mdf->id, 'external_id' => '15377', 'title' => 'x', 'image_url' => 'https://x.test/a.jpg', 'url_hash' => 'a', 'caption' => 'לוחות MDF בגוון חום בהיר.', 'captioned_at' => now()]);
        RetrievalImage::query()->create(['product_id' => $disc->id, 'external_id' => '30222', 'title' => 'y', 'image_url' => 'https://x.test/b.jpg', 'url_hash' => 'b', 'error' => 'unreachable']);

        Livewire::test(ScanSummary::class)
            ->assertSee('סיכום סריקה')
            ->assertSee('תמונות שנקראו')
            ->call('select', $mdf->id)
            ->assertSee('פלטה / לביד / MDF')
            ->assertSee('קטגוריה')
            ->assertSee('מרקם סיבי')
            ->assertSee('לוחות MDF בגוון חום בהיר.')
            ->call('pick', 'image')
            ->assertSee('דיסק סקוץ׳ למכונת פוליש')
            ->assertSee('תמונה לא נקראה');
    }

    public function test_the_stores_own_categories_and_tags_show_among_the_tags_even_with_no_vocabulary(): void
    {
        // Gil Travel, 29.10: a tour in Japan and Jewish Heritage Tours, and nothing in its tags box
        $tour = $this->product('901', '10 Day Complete Japan Experience With Jewish Heritage');
        $tour->forceFill(['payload' => ['tags' => ['Japan', 'Kyoto']]])->save();
        foreach (['Japan', 'Jewish Heritage Tours', 'Old tours'] as $i => $name) {
            $category = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c'.$i, 'name' => $name, 'path' => [$name], 'depth' => 0, 'product_count' => 1, 'hash' => 'h'.$i,
                'removed_at' => $name === 'Old tours' ? now() : null]);
            $tour->categories()->attach($category->id);
        }
        $this->fact($tour, FactKind::Type, 'jewish heritage tours', 'category');

        $this->assertSame(['Japan', 'Kyoto'], ScanSummary::storeTags($tour->fresh(), ['jewish heritage tours']),
            'each once, none a tag already says, none the store removed');

        Livewire::test(ScanSummary::class)
            ->call('select', $tour->id)
            ->assertSee('Kyoto')
            ->assertSee('מהחנות');

        // Shopify's tags as one line
        $shirt = $this->product('902', 'Linen shirt');
        $shirt->forceFill(['payload' => ['tags' => 'summer, linen ,summer']])->save();
        $this->assertSame(['summer', 'linen'], ScanSummary::storeTags($shirt->fresh(), []));
    }

    public function test_the_team_removes_a_tag_adds_one_and_scans_the_product_again(): void
    {
        $mdf = $this->product('15377', 'פלטת לביד MDF במבחר גדלים');
        $wrong = $this->fact($mdf, FactKind::Tag, 'שחור', 'variant');
        RetrievalImage::query()->create(['product_id' => $mdf->id, 'external_id' => '15377', 'title' => 'x', 'image_url' => 'https://x.test/a.jpg', 'url_hash' => 'a', 'caption' => 'חום', 'caption_key' => 'k', 'captioned_at' => now()]);

        Livewire::test(ScanSummary::class)
            ->call('select', $mdf->id)
            ->call('removeTag', $wrong->id)
            ->assertDontSee('שחור')
            ->set('newTag', 'מתאים למדפים')
            ->call('addTag')
            ->assertSee('מתאים למדפים')
            ->assertSee('ידני')
            ->call('rescan');

        $this->assertSame(FactStatus::Rejected, $wrong->fresh()->status);
        $this->assertTrue(EnrichmentFact::query()->where('product_id', $mdf->id)->where('value_text', 'מתאים למדפים')->where('origin', FactOrigin::Person)->exists());
        $this->assertSame([[$this->shop->id, 'product', '15377']], $this->rereads, 'the code readers run on it now');
        $this->assertNull(RetrievalImage::query()->where('product_id', $mdf->id)->value('caption_key'), 'its picture is read first at the next scan');
    }

    public function test_the_product_shows_the_text_its_search_vectors_were_made_from(): void
    {
        $trimmer = $this->product('55562', 'גוזם גדר חי חשמלי 620W');
        $trimmer->forceFill(['payload' => ['description' => str_repeat('גוזם גדר החי החשמלי בהספק 620W הוא כלי גינון עוצמתי. ', 20)]])->save();
        foreach (['גוזם גדר חי חשמלי 620W'."\nBrand: Hunter", 'גוזם גדר חי חשמלי 620W'."\nהספק: 620W"] as $i => $text) {
            RetrievalChunk::query()->create(['shop_id' => $this->shop->id, 'source' => 'product', 'source_id' => $trimmer->id, 'external_id' => '55562', 'title' => $trimmer->title, 'position' => $i, 'text' => $text, 'text_hash' => hash('sha256', $text), 'embedded_at' => now()]);
        }

        Livewire::test(ScanSummary::class)
            ->call('select', $trimmer->id)
            ->assertSee('מה החיפוש יודע')
            ->assertSee('2 חלקים')
            ->assertSee('Brand: Hunter')
            ->assertSee('החיפוש מכיר פחות ממחצית התיאור שבאתר');
    }

    public function test_the_product_shows_its_options_measurements_and_variants(): void
    {
        $shelf = $this->product('700', 'מדף עץ אורן');
        $shelf->forceFill(['payload' => [
            'attributes' => [['key' => 'pa_length', 'name' => 'אורך', 'values' => ['60 ס״מ', '80 ס״מ'], 'used_for_variations' => true]],
            'dimensions' => ['length' => '80', 'width' => '20', 'height' => '2', 'dimension_unit' => 'cm', 'weight' => '1.5', 'weight_unit' => 'kg'],
        ]])->save();
        foreach ([['701', 'מדף עץ אורן - 60 ס״מ', true], ['702', 'מדף עץ אורן - 80 ס״מ', false]] as $i => [$id, $title, $stock]) {
            CatalogVariant::query()->create(['product_id' => $shelf->id, 'external_id' => $id, 'position' => $i, 'title' => $title, 'sku' => 'S'.$id, 'price' => '49', 'in_stock' => $stock, 'purchasable' => $stock]);
        }

        Livewire::test(ScanSummary::class)
            ->call('select', $shelf->id)
            ->assertSee('וריאנטים ומידות')
            ->assertSee('80 × 20 × 2 cm')
            ->assertSee('1.5 kg')
            ->assertSee('60 ס״מ, 80 ס״מ')
            ->assertSee('2 וריאנטים')
            ->assertSee('מדף עץ אורן - 80 ס״מ')
            ->assertSee('אזל');

        $this->assertStringContainsString("Dimensions: 80 × 20 × 2 cm\nWeight: 1.5 kg", ProductDocuments::text($shelf->fresh()));
    }

    public function test_prices_are_in_the_shops_own_currency(): void
    {
        $shelf = $this->product('800', 'מדף אלון');
        $shelf->forceFill(['price' => '149.90'])->save();
        CatalogVariant::query()->create(['product_id' => $shelf->id, 'external_id' => '801', 'position' => 0, 'title' => 'מדף אלון - 60 ס״מ', 'sku' => 'S801', 'price' => '120', 'in_stock' => true, 'purchasable' => true]);

        Livewire::test(ScanSummary::class)->call('select', $shelf->id)->assertSee('₪149.90')->assertSee('₪120');

        $this->shop->forceFill(['currency' => 'USD'])->save();
        Filament::setTenant($this->shop->fresh());
        Livewire::test(ScanSummary::class)->call('select', $shelf->id)->assertSee('$149.90')->assertSee('$120')->assertDontSee('₪');
    }

    public function test_what_it_was_understood_to_be_about_is_shown_with_what_each_tag_rests_on(): void
    {
        $book = $this->product('1000', 'The Lean Startup');
        $meaning = new class implements ProductMeaning
        {
            /** @var list<array{string, bool}> */
            public array $decided = [];

            public function of(string $type, string $id, string $locale): array
            {
                return [
                    ['id' => 'a1', 'team' => false, 'group' => 'נושא', 'label' => 'יזמות ועסקים', 'weight' => 1.0, 'source' => 'catalog', 'evidence' => 'ספר היזמות והניהול החשוב ביותר', 'explicit' => false, 'status' => 'kept', 'note' => null],
                    ['id' => 'a2', 'team' => false, 'group' => 'נושא', 'label' => 'מנהיגות וניהול', 'weight' => 0.6, 'source' => 'catalog', 'evidence' => 'יזמים, ממנהלים', 'explicit' => false, 'status' => 'rejected', 'note' => 'הספר לא עוסק במנהיגות'],
                ];
            }

            public function decide(string $assignmentId, bool $keep): void
            {
                $this->decided[] = [$assignmentId, $keep];
            }
        };
        $this->app->instance(ProductMeaning::class, $meaning);

        Livewire::test(ScanSummary::class)
            ->call('select', $book->id)
            ->assertSee('מה הבנו עליו')
            ->assertSeeHtml('<strong>יזמות ועסקים</strong>')
            ->assertSee('מרכזי')
            ->assertSee('מטקסט החנות')
            ->assertSee('מבוסס על: «ספר היזמות והניהול החשוב ביותר»')
            ->assertSee('תגית אחת נפסלה בבדיקה')
            ->assertSee('הספר לא עוסק במנהיגות')
            ->assertSee('להחזיר')
            ->call('decideMeaning', 'a2', true)
            ->call('decideMeaning', 'a1', false);

        $this->assertSame([['a2', true], ['a1', false]], $meaning->decided, 'the one the check took out comes back; the other goes');
    }

    private function product(string $external, string $title): CatalogProduct
    {
        return CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $external, 'title' => $title, 'type' => 'simple', 'status' => 'publish', 'purchasable' => true, 'in_stock' => true, 'hash' => 'h'.$external, 'payload' => []]);
    }

    private function fact(CatalogProduct $product, FactKind $kind, string $label, string $source): EnrichmentFact
    {
        return EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => $kind, 'key' => 'k'.md5($label), 'value_text' => $label, 'quote' => $label,
            'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => md5($label), 'evidence' => ['source' => $source],
        ]);
    }
}
