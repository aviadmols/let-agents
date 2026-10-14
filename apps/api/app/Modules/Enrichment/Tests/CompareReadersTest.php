<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Actions\CompareReaders;
use App\Modules\Enrichment\Filament\Operator\Pages\ProductGaps;
use App\Modules\Enrichment\Jobs\CompareReadersJob;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two reading models on the same products before choosing one: each answer through the code's
 * checks and then undone, so the comparison is kept and nothing either model said.
 */
final class CompareReadersTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<string> */
    public array $asked = [];

    private CatalogCategory $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $this->root = $this->category('1751', 'כלי עבודה חשמליים');
        $this->powerToolsVocabulary();
        Settings::set('enrichment.compare_products', 5, $this->shop->id);

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly CompareReadersTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->asked[] = $model;
                $id = (string) json_decode($user, true)['id'];

                // The night's reader calls each product a drill driver; the other calls them hammer drills.
                return new ModelReply(['id' => $id, 'type' => $model === 'gpt-5.4-nano' ? 'drill_driver' : 'hammer_drill'], 1000, 100);
            }
        });
    }

    public function test_both_models_read_the_same_products_and_nothing_either_said_is_kept(): void
    {
        foreach (range(1, 7) as $i) {
            $this->product((string) $i, "מוצר {$i}", 'כלי חזק לעבודות בית.', [$this->root]);
        }

        $run = app(CompareReaders::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(5, $run->output['products'], 'the setting\'s five, not all seven');
        $this->assertSame(5, count(array_filter($this->asked, fn (string $m): bool => $m === 'gpt-5.4-nano')));
        $this->assertSame(5, count(array_filter($this->asked, fn (string $m): bool => $m === 'gpt-5.4-mini')));

        $a = $run->output['readers']['a'];
        $b = $run->output['readers']['b'];
        $this->assertSame(['gpt-5.4-nano', 'gpt-5.4-mini'], [$a['model'], $b['model']]);
        $this->assertSame([5, 5], [$a['facts'], $b['facts']], 'a type for each product, each model');
        $this->assertSame(['same' => 0, 'different' => 5, 'only_a' => 0, 'only_b' => 0], $run->output['agreement']);
        $this->assertSame('type', $run->output['differences'][0]['key']);
        $this->assertSame(['drill_driver', 'hammer_drill'], [$run->output['differences'][0]['a'], $run->output['differences'][0]['b']]);
        $this->assertEqualsWithDelta((1000 * 1.0 + 100 * 8.0) / 1_000_000 * 5, $b['cost_usd'], 0.000001, 'priced at the compared model\'s own price');

        $this->assertSame(0, $this->inShop(fn (): int => EnrichmentFact::query()->whereIn('origin', ['model', 'code+model'])->count()), 'no fact kept');
        $this->assertSame(0, $this->inShop(fn (): int => EnrichmentBatch::query()->count()), 'no question left behind');

        $this->asked = [];
        $again = app(CompareReaders::class)->handle($this->shop->id);
        $this->assertSame(array_column($run->output['differences'], 'product'), array_column($again->output['differences'], 'product'), 'the same products again');
    }

    public function test_the_operator_starts_it_from_the_gaps_screen_and_reads_the_result_there(): void
    {
        $this->product('1', 'מוצר 1', 'כלי חזק.', [$this->root]);
        app(CompareReaders::class)->handle($this->shop->id);

        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Bus::fake();

        Livewire::test(ProductGaps::class)
            ->assertSeeHtml('data-compare-readers')
            ->assertSee('gpt-5.4-mini')
            ->assertSee('drill_driver')
            ->call('compareReaders');

        Bus::assertDispatched(CompareReadersJob::class, fn (CompareReadersJob $job): bool => $job->shopId === $this->shop->id);
    }

    public function test_the_same_model_twice_is_not_a_comparison(): void
    {
        Settings::set('enrichment.compare_model', 'gpt-5.4-nano');
        $this->product('1', 'מוצר 1', 'כלי חזק.', [$this->root]);

        $this->assertSame('failed', app(CompareReaders::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->asked);
    }
}
