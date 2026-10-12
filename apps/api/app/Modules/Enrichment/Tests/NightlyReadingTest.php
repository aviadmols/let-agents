<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\RunNightlyReading;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop is read again every night without anyone typing anything.
 */
final class NightlyReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        // These sections are built here from the relations computed at night.
        Features::override('enrichment.relations', true, $this->shop->id);
    }

    public function test_one_night_reads_the_products_the_promises_and_the_articles(): void
    {
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה עשויה בעבודת יד מ־100% פלדה, תוצרת גרמניה.', []);
        $this->article('900', 'איך בוחרים מקדחה', "טקסט פתיחה כאן.\n- חשוב לבדוק את המתח לפני הקנייה");

        $run = app(RunNightlyReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame([], $run->output['failed'], 'every reader finished');
        $this->assertCount(count(RunNightlyReading::STEPS), $run->output['steps']);

        $facts = $this->inShop(fn () => EnrichmentFact::query()->where('status', FactStatus::Approved)->get());

        $this->assertTrue($facts->contains(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Promise), 'the promises were read');
        $this->assertTrue($facts->contains(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Highlight), 'and the article');
    }

    public function test_the_scheduled_walk_takes_every_shop_with_a_catalogue_and_skips_one_that_said_no(): void
    {
        $this->product('10', 'מקדחה', 'x', []);

        $optedOut = Shop::factory()->create();
        app(TenantContext::class)->run($optedOut->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $optedOut->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish',
            'title' => 'x', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => 'h', 'payload' => [], 'synced_at' => now(),
        ]));
        Features::override('enrichment.nightly', false, $optedOut->id);

        $this->artisan('enrichment', ['step' => 'nightly', '--scheduled' => true])
            ->expectsOutputToContain($optedOut->slug.': off')
            ->assertSuccessful();

        $read = app(TenantContext::class)->runUnscoped(fn () => Run::query()
            ->where('action', RunNightlyReading::ACTION)
            ->pluck('shop_id')
            ->all());

        $this->assertSame([$this->shop->id], $read, 'only the shop that did not opt out');
    }
}
