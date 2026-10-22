<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Contracts\EmbedderTrials;
use App\Modules\Retrieval\Jobs\RefreshVectorsJob;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Retrieval\Support\EmbeddingChoice;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every shop's words moved to another embedding model at once (retrieval:switch-embedder):
 *
 *   1. try      one word embedded with the new model, so a missing key or a wrong name changes nothing
 *   2. choose   the model and the thresholds read against its similarities become the default, in one
 *               transaction; a shop's own values for them (made for the old model) are dropped, and the
 *               shops measured apart get theirs
 *   3. forget   every vector of the old words' model deleted: pieces, the questions of sections, wordings
 *               shoppers typed, pictures' descriptions (their text stays: only the words are embedded again)
 *   4. embed    each shop's index again with the indexer, run after run until nothing is left; then the
 *               pictures' descriptions, where the shop has them
 *
 * Between 2 and the end of 4 a shop's search finds by spelling alone: vectors are only ever compared
 * with vectors of the shop's model, and there are none yet. Run again after a stop: what has a vector
 * of the new model is not embedded again.
 */
final class SwitchEmbedder
{
    public const ACTION = 'retrieval.switch_embedder';

    /** The settings that name the words' model. */
    public const MODEL_KEYS = ['retrieval.embedding_provider', 'retrieval.embedding_model', 'retrieval.embedding_dimensions', 'retrieval.embedding_usd_per_million'];

    /** The thresholds read against the words' model's similarities (as search:compare-embedders measures them). */
    public const THRESHOLDS = [
        'search.semantic_min_similarity',
        'search.meaning_only_min_similarity',
        'search.content_min_similarity',
        'assistant.site_min_similarity',
        'retrieval.min_alternative_similarity',
    ];

    /** Hebrew runs at about two characters a token, as the index estimates it. */
    private const CHARS_PER_TOKEN = 2;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Embedder $embedder,
        private readonly BuildIndex $index,
        private readonly CaptionImages $captions,
        private readonly EmbedderTrials $trials,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * What embedding a shop again would send, and roughly what it costs.
     *
     * @return array{pieces: int, asks: int, captions: int, tokens: int, usd: float}
     */
    public function estimate(string $shopId, EmbeddingChoice $target): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $target): array {
            $model = $target->model();
            $size = $this->trials->size($shopId);
            $done = RetrievalChunk::query()->where('embedding_model', $model)->whereNotNull('embedding')
                ->selectRaw('count(*) as n, coalesce(sum(length(text)), 0) as chars')->first();
            $pieces = max(0, $size['pieces'] - (int) ($done->n ?? 0));
            $chars = max(0, $size['chars'] - (int) ($done->chars ?? 0));
            $asked = RetrievalChunk::query()->where('embedding_model', $model)->whereNotNull('ask_embedding')->count();
            $asks = max(0, $size['asks'] - $asked);
            $askChars = $size['asks'] > 0 ? (int) round($size['ask_chars'] * $asks / $size['asks']) : 0;
            [$captions, $captionChars] = [0, 0];

            if (Features::enabled('retrieval.image_index', $shopId) && Features::enabled('retrieval.image_captions', $shopId)) {
                foreach (RetrievalImage::query()->whereNotNull('caption_key')->where(fn ($q) => $q->whereNull('caption_model')->orWhere('caption_model', '!=', $model))
                    ->get(['id', 'caption', 'caption_words']) as $image) {
                    $text = CaptionImages::text((string) $image->caption, array_values((array) $image->caption_words));
                    $captions += $text === '' ? 0 : 1;
                    $captionChars += mb_strlen($text);
                }
            }

            $tokens = (int) ceil(($chars + $askChars + $captionChars) / self::CHARS_PER_TOKEN);

            return ['pieces' => $pieces, 'asks' => $asks, 'captions' => $captions, 'tokens' => $tokens, 'usd' => round($tokens * $target->usdPerMillion / 1_000_000, 4)];
        });
    }

    /**
     * Steps 1 to 3: the new model tried with one word, then chosen, then the old vectors deleted. Nothing
     * changes when the try fails.
     *
     * @param  array<string, float>  $defaults  threshold => value, for every shop
     * @param  array<string, array<string, float>>  $own  shop id => threshold => value
     * @return array{run: Run, cleared: list<string>, forgotten: array<string, int>}
     *
     * @throws RuntimeException
     */
    public function choose(EmbeddingChoice $target, array $defaults, array $own): array
    {
        $cleared = [];
        $forgotten = [];

        $run = $this->runs->track(
            agent: BuildIndex::AGENT,
            action: self::ACTION,
            input: ['model' => $target->choice->label(), 'dimensions' => $target->dimensions, 'thresholds' => $defaults, 'shops' => $own],
            work: function (RunContext $run) use ($target, $defaults, $own, &$cleared, &$forgotten): void {
                $from = ModelChoice::for('embedding')->label();

                // 1. One word: a key that is missing or a model that does not exist stops it here.
                $this->spend->assertCanSpend(0.0001);
                $tried = $this->embedder->embed($target->choice->provider, $target->model(), ['בדיקה'], $target->dimensions);
                $run->usage($target->choice->providerName, $target->model(), $tried->inputTokens, 0, 0, $tried->costUsd($target->usdPerMillion));
                if ($target->dimensions !== null && $tried->dimensions() !== $target->dimensions) {
                    throw new RuntimeException("{$target->model()} answered with {$tried->dimensions()} numbers, not {$target->dimensions}.");
                }

                $cleared = DB::transaction(fn (): array => $this->settings($target, $defaults, $own));
                $forgotten = $this->forget($target->model());

                $run->output(['from' => $from, 'to' => $target->choice->label(), 'cleared' => $cleared, 'forgotten' => $forgotten])
                    ->summary('retrieval::runs.switched_embedder', ['from' => $from, 'to' => $target->choice->label()]);
            },
        );

        if ($run->status->value !== 'succeeded') {
            throw new RuntimeException((string) ($run->error ?: 'the switch did not complete'));
        }

        return ['run' => $run, 'cleared' => $cleared, 'forgotten' => $forgotten];
    }

    /**
     * Step 4 for one shop: the indexer run after run until no piece waits, then the pictures' descriptions.
     *
     * @return array{done: bool, stopped: string|null, runs: list<Run>, cost_usd: float}
     */
    public function embed(string $shopId, int $maxRounds = 100): array
    {
        $out = ['done' => false, 'stopped' => null, 'runs' => [], 'cost_usd' => 0.0];

        for ($round = 0; $round < max(1, $maxRounds); $round++) {
            $run = $this->index->handle($shopId);
            $out['runs'][] = $run;
            $embedding = (array) (((array) $run->output)['embedding'] ?? []);
            $out['cost_usd'] += (float) ($embedding['cost_usd'] ?? 0);

            if ($run->status->value !== 'succeeded' || ($embedding['stopped'] ?? null) !== null) {
                $out['stopped'] = (string) ($embedding['stopped'] ?? 'failed');

                return $out;
            }

            if ((int) ($embedding['pending'] ?? 0) === 0) {
                break;
            }

            if (! RefreshVectorsJob::more($embedding)) {
                $out['stopped'] = 'no_progress';

                return $out;
            }
        }

        if ((int) ($embedding['pending'] ?? 0) > 0) {
            $out['stopped'] = 'max_rounds';

            return $out;
        }

        // Descriptions are written already: only their words are embedded again (CaptionImages), part after part.
        if (Features::enabled('retrieval.image_index', $shopId) && Features::enabled('retrieval.image_captions', $shopId)) {
            for ($round = 0; $round < max(1, $maxRounds); $round++) {
                $run = $this->captions->handle($shopId);
                $out['runs'][] = $run;
                $stopped = ((array) $run->output)['stopped'] ?? null;
                $out['cost_usd'] += (float) (((array) $run->output)['cost_usd'] ?? 0);

                if ($run->status->value !== 'succeeded' || ($stopped !== null && $stopped !== 'more')) {
                    $out['stopped'] = 'captions: '.($stopped ?? 'failed');

                    return $out;
                }

                if ($stopped === null) {
                    break;
                }
            }
        }

        $out['done'] = true;

        return $out;
    }

    /**
     * The new defaults, a shop's own values for them dropped, the measured shops' set.
     *
     * @param  array<string, float>  $defaults
     * @param  array<string, array<string, float>>  $own
     * @return list<string> "slug key=value" of each shop's own value dropped
     */
    private function settings(EmbeddingChoice $target, array $defaults, array $own): array
    {
        $values = [
            'retrieval.embedding_provider' => $target->choice->providerName,
            'retrieval.embedding_model' => $target->model(),
            'retrieval.embedding_dimensions' => (int) $target->dimensions,
            'retrieval.embedding_usd_per_million' => $target->usdPerMillion,
            ...array_intersect_key($defaults, array_flip(self::THRESHOLDS)),
        ];

        foreach ($values as $key => $value) {
            Settings::set($key, $value);
        }

        $cleared = [];

        foreach ($this->tenant->runUnscoped(fn () => Shop::query()->orderBy('slug')->get(['id', 'slug'])) as $shop) {
            foreach ([...self::MODEL_KEYS, ...self::THRESHOLDS] as $key) {
                $was = Settings::overrideFor($key, $shop->id);
                $wanted = $own[$shop->id][$key] ?? null;

                if ($was !== null && $was !== $wanted) {
                    Settings::clear($key, $shop->id);
                    $cleared[] = "{$shop->slug} {$key}={$was}";
                }
                if ($wanted !== null && $was !== $wanted) {
                    Settings::set($key, $wanted, $shop->id);
                }
            }
        }

        return $cleared;
    }

    /**
     * Every vector of a words' model other than this one, in every shop: never to be compared again.
     * The pictures' own vectors (the picture model's) are not words' and stay.
     *
     * @return array{pieces: int, wordings: int, captions: int}
     */
    private function forget(string $model): array
    {
        return $this->tenant->runUnscoped(fn (): array => [
            'pieces' => RetrievalChunk::query()->whereNotNull('embedding_model')->where('embedding_model', '!=', $model)
                ->update(['embedding' => null, 'embedding_model' => null, 'dimensions' => null, 'embedded_at' => null, 'ask_embedding' => null, 'asks_hash' => null]),
            // a picture search's words and photos are kept as image:<model> and photo:<model>
            'wordings' => RetrievalQueryVector::query()->where('embedding_model', '!=', $model)
                ->where('embedding_model', 'not like', 'image:%')->where('embedding_model', 'not like', 'photo:%')->delete(),
            // the description stays and keeps its old model's name, so CaptionImages embeds its words again without reading the picture
            'captions' => RetrievalImage::query()->whereNotNull('caption_embedding')->where(fn ($q) => $q->whereNull('caption_model')->orWhere('caption_model', '!=', $model))
                ->update(['caption_embedding' => null]),
        ]);
    }
}
