<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Retrieval\Support\QueryVectors;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding by meaning from words a shopper typed: each wording embedded once, its cost on one run
 * a day, and nothing at all when the budget is spent.
 */
final class SearchByTextTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה לקידוח בעץ ובמתכת.');
        $this->product('20', 'סט מקדחים לבטון', 'מקדחים מוקשים לקידוח בבטון.');
        $this->article('500', 'איך לבחור מקדחה נטענת', 'מקדחה נטענת נבחרת לפי מתח הסוללה.', ['10']);
        app(BuildIndex::class)->handle($this->shop->id);
    }

    public function test_typed_words_find_the_nearest_documents_and_are_embedded_once(): void
    {
        $calls = $this->embedder->calls;

        $hits = $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'קידוח בבטון', ['product'], 5));

        $this->assertSame('20', $hits[0]['external_id'], 'the concrete bits are nearest to drilling in concrete');
        $this->assertSame(['product'], array_values(array_unique(array_column($hits, 'source'))), 'only the sources asked for');
        $this->assertSame($calls + 1, $this->embedder->calls);

        $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'קידוח בבטון', ['product', 'content'], 5));
        $this->assertSame($calls + 1, $this->embedder->calls, 'the same words cost nothing the second time');

        $saved = $this->inShop(fn () => RetrievalQueryVector::query()->sole());
        $this->assertSame([2, 'text-embedding-3-small'], [$saved->uses, $saved->embedding_model]);
    }

    public function test_the_cost_of_new_queries_adds_up_on_one_run_a_day(): void
    {
        $this->inShop(function (): void {
            app(SemanticSearch::class)->nearText($this->shop->id, 'מקדחה חזקה', ['product'], 3);
            app(SemanticSearch::class)->nearText($this->shop->id, 'ברגים לבטון', ['product'], 3);
        });

        $runs = Run::query()->where('agent', QueryVectors::AGENT)->get();
        $this->assertCount(1, $runs);
        $this->assertSame('2', $runs[0]->summary_params['count']);
        $this->assertSame(20, $runs[0]->input_tokens, 'the fake embedder counts 10 tokens a text');
        $this->assertGreaterThan(0, $runs[0]->output['cost_usd'], 'kept exactly, though a query costs millionths of a dollar');
        $this->assertSame(2, $runs[0]->output['queries']);
        $this->assertStringContainsString('2', (string) $runs[0]->summary());
    }

    public function test_without_budget_there_is_no_vector_and_no_error(): void
    {
        $this->app->instance(SpendGuard::class, new class implements SpendGuard
        {
            public function assertCanSpend(float $estimatedUsd): void
            {
                throw new SpendCapReached(6.0, $estimatedUsd, 6.0);
            }

            public function spentThisMonth(): float
            {
                return 6.0;
            }

            public function cap(): float
            {
                return 6.0;
            }
        });
        $calls = $this->embedder->calls;

        $hits = $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'משהו חדש לגמרי', ['product'], 3));

        $this->assertSame([], $hits);
        $this->assertSame($calls, $this->embedder->calls);
        $this->assertSame(0, $this->inShop(fn () => RetrievalQueryVector::query()->count()));
    }
}
