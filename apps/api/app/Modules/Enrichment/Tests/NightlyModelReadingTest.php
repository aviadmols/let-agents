<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\RunNightlyModelReading;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The model reading that used to need a person to carry a file now happens by itself, a bounded
 * number of products a night, and stops on the month's cap rather than on anyone's attention.
 */
final class NightlyModelReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private object $model;

    private CatalogCategory $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->root = $this->category('1751', 'כלי עבודה חשמליים');
        $this->powerToolsVocabulary();

        $this->model = new class implements ChatModel
        {
            public int $calls = 0;

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;

                // Whatever a task makes of it, the answer is checked in code before it is kept.
                return new ModelReply([], 300, 40);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);
    }

    public function test_it_reads_only_as_many_products_a_night_as_the_shop_allows(): void
    {
        foreach (range(1, 6) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }

        Settings::set('enrichment.nightly_model_requests', 2, $this->shop->id);

        $run = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(2, $this->model->calls, 'two a night means two');
        $this->assertSame(2, $run->output['asked']);
    }

    public function test_zero_a_night_means_no_model_is_called_at_all(): void
    {
        $this->product('1', 'מקדחה נטענת 18V', 'x', [$this->root]);
        Settings::set('enrichment.nightly_model_requests', 0, $this->shop->id);

        app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame(0, $this->model->calls);
    }

    public function test_the_month_cap_stops_the_night_and_writes_nothing_past_it(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }
        Settings::set('enrichment.nightly_model_requests', 3, $this->shop->id);

        $this->app->instance(SpendGuard::class, new class implements SpendGuard
        {
            public function assertCanSpend(float $usd, bool $forShopper = false): void
            {
                throw new SpendCapReached(6.0, 0.01, 6.0);
            }

            public function spentThisMonth(): float
            {
                return 0.0;
            }

            public function cap(): float
            {
                return 6.0;
            }

            public function spentThisMonthFor(string $shopId): float
            {
                return 0.0;
            }

            public function capFor(string $shopId): float
            {
                return 30.0;
            }
        });

        $run = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, 'a full month is not a failure');
        $this->assertSame(0, $this->model->calls, 'nothing was asked');
        $this->assertSame('spend_cap', $run->output['stopped']);
        $this->assertSame(0, $this->inShop(fn (): int => EnrichmentFact::query()->where('origin', FactOrigin::Model)->count()), 'and nothing was written');
    }

    public function test_it_moves_on_to_the_next_vocabulary_when_the_first_has_nothing_left(): void
    {
        // Two branches. The first by name has nothing a model has not read; the second does.
        $adhesives = $this->category('1749', 'הדבקה ואטימה');
        $this->product('50', 'סיליקון שקוף 280', 'סיליקון איכותי לאטימה.', [$adhesives]);
        $data = ImportVocabulary::template('adhesives');
        $data['root_category_external_id'] = '1749';
        app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test');

        foreach (range(1, 3) as $i) {
            $this->product("6{$i}", "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }

        Settings::set('enrichment.nightly_model_requests', 5, $this->shop->id);

        $run = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertGreaterThan(0, $run->output['asked'], 'the branch with unread products was found');
    }

    public function test_it_finishes_a_batch_somebody_exported_and_abandoned(): void
    {
        foreach (range(1, 2) as $i) {
            $this->product("7{$i}", "מקדחה נטענת {$i} 18V", 'מקדחה חזקה לעבודות בית.', [$this->root]);
        }

        // Exported to an outside agent, and the answers never came back. Those products count as
        // already asked, so without this nothing would ever pick them up again.
        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole());
        $abandoned = app(CreateTaskFile::class)
            ->handle($this->shop->id, TaskType::ProductExtraction, $vocabulary->id)['batch'];

        $this->assertNotNull($abandoned);
        $waiting = $this->inShop(fn (): int => EnrichmentBatchItem::query()->where('batch_id', $abandoned->id)->count());
        $this->assertGreaterThan(0, $waiting);

        Settings::set('enrichment.nightly_model_requests', 50, $this->shop->id);
        $run = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame($abandoned->id, $run->output['batch'], 'the oldest waiting questions are answered first');
        $this->assertSame($waiting, $run->output['asked'], 'and all of them');
    }
}
