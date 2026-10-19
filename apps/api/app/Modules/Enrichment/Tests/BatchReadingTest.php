<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Actions\CollectProviderBatches;
use App\Modules\Enrichment\Actions\RunNightlyModelReading;
use App\Modules\Enrichment\Actions\RunNightlyReview;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The night hands its questions to the provider's batch API and the day collects the answers,
 * through the same checks as one at a time, at half the price. The first scan hands over every
 * product a model has not read, not only a night's share.
 */
final class BatchReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private object $provider;

    private CatalogCategory $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $this->root = $this->category('1751', 'כלי עבודה חשמליים');
        $this->powerToolsVocabulary();

        $this->provider = new class implements BatchChatModel
        {
            /** @var list<list<BatchRequest>> */
            public array $submitted = [];

            public string $state = BatchState::RUNNING;

            public function supports(AiProviderName $provider): bool
            {
                return true;
            }

            public function submit(AiProviderName $provider, string $model, array $requests): string
            {
                $this->submitted[] = $requests;

                return 'pb-'.count($this->submitted);
            }

            public function state(AiProviderName $provider, string $batchId): BatchState
            {
                return new BatchState($this->state, 1, 1, 0);
            }

            public function results(AiProviderName $provider, string $model, string $batchId, ?string $system = null, array $sent = []): iterable
            {
                $index = (int) substr($batchId, 3) - 1;

                foreach ($this->submitted[$index] as $request) {
                    $id = (string) json_decode($request->user, true)['id'];

                    // The first answer is about the product it was asked about; the second names another.
                    yield new BatchResult($request->customId, new ModelReply(['id' => $request === $this->submitted[$index][0] ? $id : 'someone-else'], 1000, 100));
                }
            }
        };
        $this->app->instance(BatchChatModel::class, $this->provider);
    }

    public function test_the_night_hands_its_share_over_and_the_collector_applies_the_answers_at_half_price(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }
        Settings::set('enrichment.nightly_model_requests', 2, $this->shop->id);
        // No catch-up: the night's own number.
        Settings::set('enrichment.nightly_batch_requests', 0, $this->shop->id);

        $night = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $night->status->value);
        $this->assertCount(1, $this->provider->submitted, 'one batch');
        $this->assertCount(2, $this->provider->submitted[0], 'the night\'s share');
        $this->assertSame('batch', $night->output['via']);
        $batch = $this->inShop(fn () => EnrichmentBatch::query()->sole());
        $this->assertSame('pb-1', $batch->provider_batch_id);
        $this->assertSame(BatchStatus::AwaitingResults, $batch->status);

        // Still with the provider: nothing is read, and the night does not hand it over twice.
        $this->assertSame(0, app(CollectProviderBatches::class)->handle($this->shop->id)?->output['collected']);
        app(RunNightlyModelReading::class)->handle($this->shop->id);
        $this->assertCount(2, $this->provider->submitted, 'the next night hands over the third product, not the two waiting');
        $this->assertCount(1, $this->provider->submitted[1]);

        $this->provider->state = BatchState::ENDED;
        Event::fake([FactsDecided::class]);
        $collected = app(CollectProviderBatches::class)->handle($this->shop->id);

        $this->assertSame(2, $collected->output['collected']);
        Event::assertDispatched(FactsDecided::class, fn (FactsDecided $e): bool => $e->shopId === $this->shop->id);
        $this->assertTrue(Run::query()->where('shop_id', $this->shop->id)->where('action', RunNightlyReview::ACTION)->exists(), 'the review starts the same day, not the next night');
        $items = $this->inShop(fn () => EnrichmentBatchItem::query()->where('batch_id', $batch->id)->orderBy('custom_id')->get());
        $this->assertEqualsCanonicalizing([ItemStatus::Applied, ItemStatus::Rejected], $items->pluck('status')->all(), 'an answer about another product is refused, as one at a time');
        $this->assertSame(BatchStatus::Completed, $batch->refresh()->status);
        $usage = DB::table('ai_usage')->where('action', 'enrichment.collect_batches')->get();
        $this->assertCount(3, $usage);
        $this->assertTrue((bool) $usage[0]->batch, 'the ledger says it went in a batch');
        $this->assertEqualsWithDelta((1000 * 0.3 + 100 * 2.5) / 1_000_000 * 0.5, (float) $usage[0]->cost_usd, 0.0000001, 'at half the price');

        $this->assertNull(app(CollectProviderBatches::class)->handle($this->shop->id), 'nothing waiting is not a run');
    }

    public function test_the_first_scan_hands_over_every_unread_product_not_only_a_nights_share(): void
    {
        foreach (range(1, 5) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }
        Settings::set('enrichment.nightly_model_requests', 1, $this->shop->id);
        Settings::set('enrichment.max_requests_per_task_file', 2, $this->shop->id);

        $run = app(RunNightlyModelReading::class)->handle($this->shop->id, allProducts: true);

        $this->assertSame(5, $run->output['submitted'], 'all five, past the night\'s one');
        $this->assertSame([2, 2, 1], array_map('count', $this->provider->submitted), 'in batches as large as a task file');
    }

    public function test_a_night_through_a_batch_catches_up_on_the_catalogue_in_rounds_up_to_its_cap(): void
    {
        foreach (range(1, 7) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }
        Settings::set('enrichment.nightly_model_requests', 1, $this->shop->id);
        Settings::set('enrichment.max_requests_per_task_file', 2, $this->shop->id);
        Settings::set('enrichment.nightly_batch_requests', 5, $this->shop->id);

        $night = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame(5, $night->output['submitted'], 'past the night\'s one, up to the catch-up cap');
        $this->assertSame([2, 2, 1], array_map('count', $this->provider->submitted), 'in rounds as large as a task file');

        app(RunNightlyModelReading::class)->handle($this->shop->id);
        $this->assertSame(7, array_sum(array_map('count', $this->provider->submitted)), 'the next night reads the rest, and nothing twice');
    }

    public function test_a_batch_the_provider_lost_goes_back_to_waiting_and_the_night_hands_it_over_again(): void
    {
        $this->product('1', 'מקדחה נטענת 18V', 'מקדחה חזקה.', [$this->root]);
        app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->provider->state = BatchState::LOST;
        $this->assertSame(1, app(CollectProviderBatches::class)->handle($this->shop->id)->output['lost']);
        $this->assertNull($this->inShop(fn () => EnrichmentBatch::query()->sole())->provider_batch_id);

        app(RunNightlyModelReading::class)->handle($this->shop->id);
        $this->assertCount(2, $this->provider->submitted, 'its questions were handed over again');
        $this->assertSame($this->provider->submitted[0][0]->customId, $this->provider->submitted[1][0]->customId);
    }
}
