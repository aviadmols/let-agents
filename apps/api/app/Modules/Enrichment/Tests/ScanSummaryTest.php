<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Merchant\Pages\ScanSummary;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
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
