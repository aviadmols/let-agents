<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\RunNightlyReview;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the reader was unsure of, checked every night by a model of the other family: right is
 * approved, wrong rejected, unsure goes to a person; the same family on both sides checks nothing.
 */
final class NightlyReviewTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: string, model: string}> */
    public array $asked = [];

    /** @var array<string, string> verdict by claim id */
    public array $verdicts = [];

    private CatalogProduct $drill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $root = $this->category('1751', 'כלי עבודה חשמליים');
        $vocabulary = $this->powerToolsVocabulary();
        Features::override('enrichment.batch_reading', false, $this->shop->id);
        $this->drill = $this->product('1', 'מקדחה נטענת 18V', 'מקדחה חזקה לעבודות בית.', [$root]);

        $this->inShop(function () use ($vocabulary): void {
            foreach (['drill_driver' => 'type', 'included' => 'kit'] as $value => $key) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $this->drill->id, 'vocabulary_id' => $vocabulary->id,
                    'kind' => $key === 'type' ? FactKind::Type : FactKind::Choice, 'key' => $key, 'value_text' => $value, 'quote' => 'מקדחה נטענת',
                    'origin' => FactOrigin::Model, 'status' => FactStatus::AwaitingReview, 'input_hash' => str_repeat('a', 64), 'model' => 'gpt-5.4-nano']);
            }
        });

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly NightlyReviewTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->asked[] = ['provider' => $provider->value, 'model' => $model];
                $request = json_decode($user, true);
                $verdicts = [];
                foreach ($request['claims'] as [$id, , $key]) {
                    $verdicts[$id] = $this->test->verdicts[$key] ?? 'unsure';
                }

                return new ModelReply(['id' => $request['id'], 'verdicts' => $verdicts], 800, 40);
            }
        });
    }

    public function test_the_other_family_checks_what_waits_right_is_approved_wrong_rejected(): void
    {
        $this->verdicts = ['type' => 'ok', 'kit' => 'wrong'];

        $run = app(RunNightlyReview::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame([['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']], $this->asked, 'Claude checks what OpenAI read');
        $statuses = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->drill->id)->pluck('status', 'key')->map(fn (FactStatus $s): string => $s->value)->all());
        $this->assertSame(['kit' => 'rejected', 'type' => 'approved'], collect($statuses)->sortKeys()->all());

        $this->asked = [];
        $this->assertSame('succeeded', app(RunNightlyReview::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->asked, 'nothing waits now: nothing is asked');
    }

    public function test_the_same_family_on_both_sides_checks_nothing(): void
    {
        Settings::set('enrichment.reviewer_provider', 'openai');

        $this->assertSame('failed', app(RunNightlyReview::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->asked);
    }

    public function test_zero_a_night_or_the_flag_off_asks_nothing(): void
    {
        Settings::set('enrichment.nightly_review_requests', 0, $this->shop->id);
        app(RunNightlyReview::class)->handle($this->shop->id);
        $this->assertSame([], $this->asked);

        Settings::set('enrichment.nightly_review_requests', 50, $this->shop->id);
        Features::override('enrichment.auto_review', false, $this->shop->id);
        app(RunNightlyReview::class)->handle($this->shop->id);
        $this->assertSame([], $this->asked);
        $this->assertNotNull($this->inShop(fn () => EnrichmentVocabulary::query()->first()));
    }
}
