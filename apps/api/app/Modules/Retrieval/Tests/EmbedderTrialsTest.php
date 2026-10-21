<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Contracts\EmbedderTrial;
use App\Modules\Retrieval\Contracts\EmbedderTrials;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Retrieval\Support\InMemoryEmbedderTrials;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Another embedding model measured on the shop's own pieces: ranked as the live search ranks by
 * meaning, embedded in memory, accounted, and never written anywhere.
 */
final class EmbedderTrialsTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    private object $pictures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה לקידוח בעץ ובמתכת.');
        $this->product('11', 'מסור עגול 1400W', 'מסור עגול לחיתוך עץ ולוחות.');
        $this->product('12', 'שמן לעץ טבעי', 'שמן להגנה על עץ בחוץ, דק ופרגולה.');
        $this->article('500', 'איך לבחור מקדחה נטענת', 'מקדחה נטענת נבחרת לפי מתח הסוללה והמומנט.', ['10']);
        app(BuildIndex::class)->handle($this->shop->id);

        // the picture model's way: a query told it is a search, the same bag of words underneath
        $this->pictures = new class($this->embedder) implements ImageEmbedder
        {
            /** @var list<bool> */
            public array $asQuery = [];

            public function __construct(private readonly object $words) {}

            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                return new Embeddings([], 0);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                $this->asQuery[] = $query;

                return new Embeddings(array_map(fn (string $t): array => $this->words::vector($t), $texts), count($texts) * 10);
            }
        };
        $this->app->instance(ImageEmbedder::class, $this->pictures);
    }

    public function test_each_trial_ranks_products_and_pages_by_their_best_piece(): void
    {
        $trials = [EmbedderTrial::index(), new EmbedderTrial('other', 'gemini', 'gemini-embedding-2', 768, 0.2), new EmbedderTrial('other as search', 'gemini', 'gemini-embedding-2', 768, 0.2, asSearch: true)];
        $results = $this->inShop(fn () => app(EmbedderTrials::class)->rank($this->shop->id, ['מסור עגול', 'שמן לעץ', ' מסור עגול '], $trials, 3, 1.0));

        $this->assertSame(['index', 'other', 'other as search'], array_keys($results));
        foreach ($results as $name => $result) {
            $this->assertNull($result['stopped'], $name);
            $this->assertSame(['מסור עגול', 'שמן לעץ'], array_keys($result['ranked']), 'each wording once, trimmed');
            $this->assertSame('p:11', $result['ranked']['מסור עגול'][0], $name);
            $this->assertSame('p:12', $result['ranked']['שמן לעץ'][0], $name);
            $this->assertLessThanOrEqual(3, count($result['ranked']['שמן לעץ']));
            $this->assertSame(count(array_unique($result['ranked']['שמן לעץ'])), count($result['ranked']['שמן לעץ']), 'a document once, by its best piece');
        }
        $this->assertSame([true], $this->pictures->asQuery, 'only the queries go the search way, once, in one batch');
        $this->assertSame($results['index']['pieces'], $results['other']['pieces'], 'every trial reads the same pieces');
    }

    public function test_nothing_is_written_and_every_trial_is_accounted(): void
    {
        $before = $this->inShop(fn () => RetrievalChunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model', 'embedding'])->toArray());
        $queries = $this->inShop(fn () => RetrievalQueryVector::query()->count());

        $results = $this->inShop(fn () => app(EmbedderTrials::class)->rank($this->shop->id, ['מקדחה'], [EmbedderTrial::index(), new EmbedderTrial('other', 'gemini', 'gemini-embedding-2', 768, 0.2)], 5, 1.0));

        $this->assertSame($before, $this->inShop(fn () => RetrievalChunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model', 'embedding'])->toArray()), 'the index is untouched');
        $this->assertSame($queries, $this->inShop(fn () => RetrievalQueryVector::query()->count()), 'no saved query vectors');
        $this->assertSame(2, Run::query()->where('agent', InMemoryEmbedderTrials::AGENT)->count(), 'a run per trial');
        $this->assertGreaterThan(0, $results['other']['input_tokens']);
        $this->assertSame('gemini-embedding-2', $this->embedder->model, 'the other model was asked for the pieces');
    }

    public function test_a_trial_stops_at_its_ceiling_and_ranks_nothing(): void
    {
        $results = $this->inShop(fn () => app(EmbedderTrials::class)->rank($this->shop->id, ['מקדחה'], [new EmbedderTrial('dear', 'gemini', 'gemini-embedding-2', 768, 1_000_000.0)], 5, 0.01));

        $this->assertSame('max_usd', $results['dear']['stopped']);
        $this->assertSame([], $results['dear']['ranked'], 'part of the shop is no ranking');
    }

    public function test_an_unknown_provider_stops_before_any_call(): void
    {
        $calls = $this->embedder->calls;
        $results = $this->inShop(fn () => app(EmbedderTrials::class)->rank($this->shop->id, ['מקדחה'], [new EmbedderTrial('nobody', 'nobody', 'x')], 5, 1.0));

        $this->assertSame('unknown_provider', $results['nobody']['stopped']);
        $this->assertSame($calls, $this->embedder->calls);
    }

    public function test_the_size_of_switching_counts_every_piece(): void
    {
        $size = $this->inShop(fn () => app(EmbedderTrials::class)->size($this->shop->id));
        $pieces = $this->inShop(fn () => RetrievalChunk::query()->count());

        $this->assertSame($pieces, $size['pieces']);
        $this->assertGreaterThan(0, $size['chars']);
        $this->assertSame(0, $size['asks'], 'no section questions written in this shop');
    }
}
