<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A product the store publishes during the day is read by code once the sync brings it, not the next night: what it is
 * reaches the search's filters and the matches the same day (Rocks, 19.11: 55 products published at noon had no kind and
 * no metal until the night after). Only products code never read, only in a shop code read before, no model.
 */
final class ReadNewProductsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<CatalogCategory> */
    private array $planed = [];

    public function test_a_new_product_is_read_once_the_sync_brings_it_and_the_search_is_told(): void
    {
        $this->readShop();
        $before = $this->inShop(fn () => EnrichmentCodeReading::query()->value('read_at'));

        $new = $this->product('11533', 'עץ אורן מוקצע בפרופיל 20X70 מ"מ (2X7)', 'עץ אורן איכותי', $this->planed, $this->filed('11533'));
        Event::fake([FactsDecided::class]);
        event(new CatalogProductsChanged($this->shop->id, ['11533']));

        $type = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $new->id)->where('kind', FactKind::Type)->sole());
        $this->assertSame('planed_board', $type->value_text, 'what it is, as its category says, by code');
        $this->assertSame(FactStatus::Approved, $type->status);
        Event::assertDispatched(FactsDecided::class, fn (FactsDecided $e): bool => $e->shopId === $this->shop->id);
        $this->assertSame((string) $before, (string) $this->inShop(fn () => EnrichmentCodeReading::query()->whereKeyNot(
            EnrichmentCodeReading::query()->where('product_id', $new->id)->value('id'))->value('read_at')), 'the products read before are not read again');

        // a sync that brings nothing new reads nothing and tells nobody
        Event::fake([FactsDecided::class]);
        event(new CatalogProductsChanged($this->shop->id, ['11533']));
        Event::assertNotDispatched(FactsDecided::class);
    }

    public function test_off_or_in_a_shop_code_never_read_nothing_is_read(): void
    {
        $this->readShop();
        Features::override('enrichment.read_new_at_once', false, $this->shop->id);
        $off = $this->product('11534', 'עץ אורן מוקצע בפרופיל 20X95 מ"מ (2X10)', 'x', $this->planed, $this->filed('11534'));
        event(new CatalogProductsChanged($this->shop->id, ['11534']));
        $this->assertFalse($this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $off->id)->exists()), 'off: the night reads it');

        // a shop whose first scan has not read its catalogue: the scan reads it whole, not this
        $this->buildShop();
        $this->categories();
        $first = $this->product('11532', 'עץ אורן מוקצע בפרופיל 20X45 מ"מ (2X5)', 'x', $this->planed, $this->filed('11532'));
        event(new CatalogProductsChanged($this->shop->id, ['11532']));
        $this->assertFalse($this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $first->id)->exists()));
    }

    public function test_the_reading_of_some_products_reads_only_them(): void
    {
        $this->buildShop();
        $this->categories();
        $a = $this->product('11532', 'עץ אורן מוקצע בפרופיל 20X45 מ"מ (2X5)', 'x', $this->planed, $this->filed('11532'));
        $b = $this->product('11533', 'עץ אורן מוקצע בפרופיל 20X70 מ"מ (2X7)', 'x', $this->planed, $this->filed('11533'));
        app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test');

        $run = app(ReadProductsInCode::class)->handle($this->shop->id, [$b->id]);

        $this->assertSame(1, $run->output['products']);
        $this->assertTrue($this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $b->id)->exists()));
        $this->assertFalse($this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $a->id)->exists()));
    }

    /** A wood store whose catalogue code has read: one planed board, its kind read from its category. */
    private function readShop(): void
    {
        $this->buildShop();
        $this->categories();
        $this->product('11532', 'עץ אורן מוקצע בפרופיל 20X45 מ"מ (2X5)', 'עץ אורן איכותי', $this->planed, $this->filed('11532'));
        app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test');
        app(ReadProductsInCode::class)->handle($this->shop->id);
    }

    private function categories(): void
    {
        $wood = $this->category('2212', 'עצים');
        $pine = $this->category('2213', 'עץ אורן', '2212', ['עצים', 'עץ אורן']);
        $planed = $this->category('2339', 'מוקצע לא מחוטא', '2213', ['עצים', 'עץ אורן', 'מוקצע לא מחוטא']);
        $this->planed = [$wood, $pine, $planed];
    }

    /** @return array<string, mixed> */
    private function filed(string $id): array
    {
        return ['payload' => ['categories' => [['id' => '2212', 'path' => ['עצים']], ['id' => '2213', 'path' => ['עצים', 'עץ אורן']], ['id' => '2339', 'path' => ['עצים', 'עץ אורן', 'מוקצע לא מחוטא']]]]];
    }
}
