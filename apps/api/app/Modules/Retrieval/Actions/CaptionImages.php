<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Contracts\CaptionsPictures;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The second vector of every scanned picture: what it shows. A model that sees pictures writes a
 * short Hebrew sentence and a few search words for each; the sentence and the words are embedded
 * with the text model in one call per batch. The picture's own vector says how it looks, this one
 * says what is in it, so a drill and a tool box of the same colour stop looking alike.
 *
 * A part at a time, like the picture scan; a picture is described again only when its address,
 * the prompt or the model changes. Every call is accounted; the spending cap holds.
 */
final class CaptionImages implements CaptionsPictures
{
    public const AGENT = 'retrieval.image_captioner';

    public const ACTION = 'retrieval.caption_images';

    public const PROMPT_VERSION = 1;

    private const BATCH = 16;

    private const MAX_WORDS = 8;

    /** A picture of about 1000px is about this many input tokens, for the spend estimate. */
    private const PHOTO_TOKENS = 1600;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly VisionModel $vision,
        private readonly Embedder $embedder,
        private readonly SpendGuard $spend,
    ) {}

    public function captions(string $shopId): Run
    {
        return $this->handle($shopId);
    }

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::System): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->describe($run, $shopId)),
        );
    }

    /** What a picture's description is keyed by: written again only when one of these changes. */
    public static function key(string $urlHash, string $model): string
    {
        return hash('sha256', $urlHash.'|'.self::PROMPT_VERSION.'|'.$model);
    }

    private function describe(RunContext $run, string $shopId): void
    {
        $stats = ['described' => 0, 'skipped' => 0, 'left' => 0, 'stopped' => null, 'cost_usd' => 0.0];
        $reader = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('retrieval.caption_provider'))));
        $model = trim((string) Settings::get('retrieval.caption_model'));
        $text = ModelChoice::for('embedding');

        if (! Features::enabled('retrieval.image_captions', $shopId)) {
            $run->output($stats + ['off' => true])->summary('retrieval::runs.captions_off');

            return;
        }

        if ($reader === null || $model === '' || $text->provider === null || $text->model === '') {
            $run->fail('retrieval::runs.captions_no_provider');

            return;
        }

        $todo = RetrievalImage::query()->whereNotNull('embedded_at')->whereNull('error')
            ->orderBy('id')->get(['id', 'title', 'image_url', 'url_hash', 'caption_key', 'caption_model'])
            ->filter(fn (RetrievalImage $i): bool => $i->caption_key !== self::key($i->url_hash, $model) || $i->caption_model !== $text->model)
            ->values();
        $limit = (int) Settings::get('retrieval.images_per_part');
        $system = (string) file_get_contents(__DIR__.'/../Prompts/caption.v'.self::PROMPT_VERSION.'.md');
        $maxOutput = (int) Settings::get('retrieval.caption_max_output_tokens');
        $in = (float) Settings::get('retrieval.caption_input_usd_per_million');
        $out = (float) Settings::get('retrieval.caption_output_usd_per_million');
        $textPrice = (float) Settings::get('retrieval.embedding_usd_per_million');
        $dimensions = (int) Settings::get('retrieval.embedding_dimensions') ?: null;

        foreach ($todo->take($limit)->chunk(self::BATCH) as $batch) {
            $described = [];

            try {
                foreach ($batch as $image) {
                    $picture = $this->fetch($image);

                    if ($picture === null) {
                        $stats['skipped']++;

                        continue;
                    }

                    $this->spend->assertCanSpend((self::PHOTO_TOKENS * $in + $maxOutput * $out) / 1_000_000);
                    $reply = $this->vision->jsonWithImage($reader, $model, $system, 'Describe this product photo.', $picture['mime'], $picture['data'], $maxOutput, 'low');
                    $cost = $reply->costUsd($in, $out);
                    $run->usage($reader->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
                    $stats['cost_usd'] += $cost;

                    $caption = trim(mb_substr((string) ($reply->data['caption'] ?? ''), 0, 300));
                    $words = array_values(array_unique(array_filter(array_map(
                        fn ($w): string => trim(mb_substr(is_string($w) ? $w : '', 0, 40)),
                        array_slice((array) ($reply->data['words'] ?? []), 0, self::MAX_WORDS),
                    ))));

                    if ($caption === '' && $words === []) {
                        $stats['skipped']++;

                        continue;
                    }

                    $described[] = ['image' => $image, 'caption' => $caption, 'words' => $words];
                }

                if ($described === []) {
                    continue;
                }

                // The description and its words, one text each, embedded together.
                $texts = array_map(fn (array $d): string => trim($d['caption'].' '.implode(', ', $d['words'])), $described);
                $this->spend->assertCanSpend(array_sum(array_map('mb_strlen', $texts)) / 2 * $textPrice / 1_000_000);
                $embeddings = $this->embedder->embed($text->provider, $text->model, $texts, $dimensions);
                $run->usage($text->providerName, $text->model, $embeddings->inputTokens, 0, 0, $embeddings->costUsd($textPrice));
                $stats['cost_usd'] += $embeddings->costUsd($textPrice);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $stats['stopped'] = $e->reason;
                break;
            }

            foreach ($described as $i => $d) {
                $vector = $embeddings->vectors[$i] ?? null;

                if ($vector === null) {
                    continue;
                }

                RetrievalImage::query()->whereKey($d['image']->id)->update([
                    'caption' => $d['caption'],
                    'caption_words' => json_encode($d['words'], JSON_UNESCAPED_UNICODE),
                    'caption_key' => self::key($d['image']->url_hash, $model),
                    'caption_model' => $text->model,
                    'caption_embedding' => VectorSearch::column($vector),
                    'captioned_at' => now(),
                ]);
                $stats['described']++;
            }
        }

        $stats['left'] = max(0, $todo->count() - $stats['described'] - $stats['skipped']);

        if ($stats['stopped'] === null && $todo->count() > $limit) {
            $stats['stopped'] = 'more';
        }

        $stats['cost_usd'] = round($stats['cost_usd'], 6);
        $run->output($stats)->summary('retrieval::runs.captioned', ['described' => (string) $stats['described'], 'left' => (string) $stats['left']]);
    }

    /** @return array{mime: string, data: string}|null */
    private function fetch(RetrievalImage $image): ?array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->get($image->image_url);
        } catch (ConnectionException) {
            return null;
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        return $response->successful() && in_array($mime, self::MIMES, true) && $body !== '' && strlen($body) <= (int) Settings::get('retrieval.image_max_kb') * 1024
            ? ['mime' => $mime, 'data' => $body]
            : null;
    }
}
