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
use App\Modules\Retrieval\Support\EmbeddingChoice;
use App\Modules\Retrieval\Support\PictureAddress;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The second vector of every scanned picture: what it shows. A model that sees pictures writes a
 * short sentence and a few search words for each, in the store's language; the sentence and the words are embedded
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

    /**
     * 4: stones by colour, light and shape, never by name: a photo cannot tell a ruby from a garnet.
     * 5: more of how it looks: the metal's colour in two words, every colour as a word of its own,
     * the finish and the style; up to 18 words.
     * 6: the same care for clothes, shoes, bags and accessories, furniture and the rest.
     */
    public const PROMPT_VERSION = 6;

    /**
     * 7: v6 in the store's language, for a store that does not write in Hebrew (an English one): its
     * titles are what the words are matched against. A Hebrew store stays on v6, its pictures as described.
     */
    public const OTHER_LANGUAGE_VERSION = 7;

    private const BATCH = 16;

    /** How long a describer holds the shop: a part of 200 pictures takes minutes, not this. */
    private const LOCK_SECONDS = 1500;

    private const MAX_WORDS = 18;

    /** A picture of about 1000px is about this many input tokens, for the spend estimate. */
    private const PHOTO_TOKENS = 1600;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->locked($run, $shopId)),
        );
    }

    /**
     * Two describers of the same shop at once would pay for the same pictures twice. A lock of its
     * own, not the picture scan's: describing does not wait for a scan that holds it, and a lock a
     * restart left behind ends with its time.
     */
    private function locked(RunContext $run, string $shopId): void
    {
        $lock = Cache::lock('retrieval:captions:'.$shopId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $run->output(['stopped' => 'already_running'])->summary('retrieval::runs.captions_busy');

            return;
        }

        try {
            $this->describe($run, $shopId);
        } finally {
            $lock->release();
        }
    }

    /** What a picture's description is keyed by: written again only when one of these changes. */
    public static function key(string $urlHash, string $model, string $language = 'Hebrew'): string
    {
        return $language === 'Hebrew'
            ? hash('sha256', $urlHash.'|'.self::PROMPT_VERSION.'|'.$model)
            : hash('sha256', $urlHash.'|'.self::OTHER_LANGUAGE_VERSION.'|'.$model.'|'.$language);
    }

    /**
     * What a description's vector is made of: the sentence and its words, as one text.
     *
     * @param  list<string>  $words
     */
    public static function text(string $caption, array $words): string
    {
        return trim($caption.' '.implode(', ', $words));
    }

    /** The language the store writes its products in, named for the model. */
    public static function language(string $shopId): string
    {
        $locale = (string) Shop::query()->whereKey($shopId)->value('content_locale');

        return ['en' => 'English'][$locale] ?? 'Hebrew';
    }

    private function describe(RunContext $run, string $shopId): void
    {
        $stats = ['described' => 0, 'skipped' => 0, 'left' => 0, 'stopped' => null, 'cost_usd' => 0.0];
        $reader = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('retrieval.caption_provider'))));
        $model = trim((string) Settings::get('retrieval.caption_model'));
        $language = self::language($shopId);
        $words = EmbeddingChoice::forShop($shopId);
        $text = $words->choice;

        if (! Features::enabled('retrieval.image_captions', $shopId)) {
            $run->output($stats + ['off' => true])->summary('retrieval::runs.captions_off');

            return;
        }

        if ($reader === null || $model === '' || $text->provider === null || $text->model === '') {
            $run->fail('retrieval::runs.captions_no_provider');

            return;
        }

        $todo = RetrievalImage::query()->whereNotNull('embedded_at')->whereNull('error')
            ->orderBy('id')->get(['id', 'title', 'image_url', 'url_hash', 'caption', 'caption_words', 'caption_key', 'caption_model'])
            ->filter(fn (RetrievalImage $i): bool => $i->caption_key !== self::key($i->url_hash, $model, $language) || $i->caption_model !== $text->model)
            ->values();
        $limit = $part = (int) Settings::get('retrieval.images_per_part');
        $system = (string) file_get_contents(__DIR__.'/../Prompts/caption.v'.($language === 'Hebrew' ? self::PROMPT_VERSION : self::OTHER_LANGUAGE_VERSION).'.md');
        $maxOutput = (int) Settings::get('retrieval.caption_max_output_tokens');
        $in = (float) Settings::get('retrieval.caption_input_usd_per_million');
        $out = (float) Settings::get('retrieval.caption_output_usd_per_million');
        $textPrice = $words->usdPerMillion;
        $dimensions = $words->dimensions;

        // Described already, only not embedded with the shop's words' model (it moved to another): its words
        // embedded again, the picture not read again.
        [$already, $todo] = $todo->partition(fn (RetrievalImage $i): bool => $i->caption_key === self::key($i->url_hash, $model, $language));
        $todo = $todo->values();
        $this->embedAgain($run, $already->take($limit)->values(), $words, $stats);
        $limit = $stats['stopped'] === null ? max(0, $limit - $already->count()) : 0;

        foreach ($todo->take($limit)->chunk(self::BATCH) as $batch) {
            $described = [];

            try {
                // The batch's pictures read side by side (retrieval.caption_parallel at once), SpendGuard first for all.
                $this->spend->assertCanSpend($batch->count() * (self::PHOTO_TOKENS * $in + $maxOutput * $out) / 1_000_000);
                $read = $this->readAll($shopId, $batch, $reader, $model, $system, $language, $maxOutput, $in, $out);

                foreach ($batch as $image) {
                    $reply = $read[$image->id] ?? ['outcome' => 'error'];

                    if ($reply['outcome'] === 'refused') {
                        // One picture the model will not read (too big, a format it refuses) is passed
                        // over for good, so the next run does not pay again for the ones before it.
                        RetrievalImage::query()->whereKey($image->id)->update(['caption_key' => self::key($image->url_hash, $model, $language), 'caption_model' => $text->model, 'caption' => null, 'captioned_at' => now()]);
                    }

                    if ($reply['outcome'] !== 'read') {
                        $stats['skipped']++;

                        continue;
                    }

                    $run->usage($reader->value, $model, $reply['input'], $reply['output'], 0, $reply['cost']);
                    $stats['cost_usd'] += $reply['cost'];

                    $caption = trim(mb_substr((string) ($reply['data']['caption'] ?? ''), 0, 500));
                    // A stone's name is never a look: a word that names one is left out, whatever the model wrote.
                    $words = array_values(array_unique(array_filter(array_map(
                        fn ($w): string => trim(mb_substr(is_string($w) ? $w : '', 0, 40)),
                        array_slice((array) ($reply['data']['words'] ?? []), 0, self::MAX_WORDS),
                    ), fn (string $w): bool => $w !== '' && preg_match(self::STONE_NAMES, $w) !== 1)));

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
                $texts = array_map(fn (array $d): string => self::text($d['caption'], $d['words']), $described);
                $this->spend->assertCanSpend(array_sum(array_map('mb_strlen', $texts)) / 2 * $textPrice / 1_000_000);
                $embeddings = $this->embedder->embed($text->provider, $text->model, $texts, $dimensions);
                $run->usage($text->providerName, $text->model, $embeddings->inputTokens, 0, 0, $embeddings->costUsd($textPrice));
                $stats['cost_usd'] += $embeddings->costUsd($textPrice);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                // The words could not be embedded (the text model's provider): the reason to the log.
                report($e);
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
                    'caption_key' => self::key($d['image']->url_hash, $model, $language),
                    'caption_model' => $text->model,
                    'caption_embedding' => VectorSearch::column($vector),
                    'captioned_at' => now(),
                ]);
                $stats['described']++;
            }
        }

        $stats['left'] = max(0, $todo->count() + $already->count() - $stats['described'] - $stats['skipped'] - ($stats['embedded_again'] ?? 0));

        if ($stats['stopped'] === null && $todo->count() + $already->count() > $part) {
            $stats['stopped'] = 'more';
        }

        $stats['cost_usd'] = round($stats['cost_usd'], 6);
        $run->output($stats)->summary('retrieval::runs.captioned', ['described' => (string) $stats['described'], 'left' => (string) $stats['left']]);
    }

    /**
     * Descriptions already written, embedded with the words' model the shop uses now; one with nothing
     * to embed (a picture passed over) is only marked so. One call per batch, SpendGuard first.
     *
     * @param  Collection<int, RetrievalImage>  $images
     * @param  array<string, mixed>  $stats
     */
    private function embedAgain(RunContext $run, Collection $images, EmbeddingChoice $words, array &$stats): void
    {
        $stats['embedded_again'] = 0;
        $model = $words->model();

        foreach ($images->chunk(self::BATCH) as $batch) {
            $texts = [];
            foreach ($batch as $image) {
                $text = self::text((string) $image->caption, array_values((array) $image->caption_words));
                if ($text === '') {
                    RetrievalImage::query()->whereKey($image->id)->update(['caption_model' => $model, 'caption_embedding' => null]);
                    $stats['embedded_again']++;

                    continue;
                }
                $texts[$image->id] = $text;
            }

            if ($texts === []) {
                continue;
            }

            try {
                $this->spend->assertCanSpend(array_sum(array_map('mb_strlen', $texts)) / 2 * $words->usdPerMillion / 1_000_000);
                $embeddings = $this->embedder->embed($words->choice->provider, $model, array_values($texts), $words->dimensions);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';

                return;
            } catch (ModelCallFailed $e) {
                report($e);
                $stats['stopped'] = $e->reason;

                return;
            }

            $run->usage($words->choice->providerName, $model, $embeddings->inputTokens, 0, 0, $embeddings->costUsd($words->usdPerMillion));
            $stats['cost_usd'] += $embeddings->costUsd($words->usdPerMillion);

            foreach (array_keys($texts) as $i => $id) {
                if (($embeddings->vectors[$i] ?? null) !== null) {
                    RetrievalImage::query()->whereKey($id)->update(['caption_model' => $model, 'caption_embedding' => VectorSearch::column($embeddings->vectors[$i])]);
                    $stats['embedded_again']++;
                }
            }
        }
    }

    /**
     * A batch's pictures, each fetched and read by the vision model, retrieval.caption_parallel at once: one at a
     * time, 300 pictures were a quarter of an hour. Side by side each in a process of its own (Concurrency), which
     * is handed only the picture's id, never its bytes, and fetches it itself; at 1, one after another here.
     *
     * @param  Collection<int, RetrievalImage>  $batch
     * @return array<int|string, array{outcome: string, data?: array<string, mixed>, input?: int, output?: int, cost?: float}> by picture id
     */
    private function readAll(string $shopId, Collection $batch, AiProviderName $reader, string $model, string $system, string $language, int $maxOutput, float $in, float $out): array
    {
        $tasks = [];
        foreach ($batch as $image) {
            // The store's name for it, so the description uses the store's words ("רובי" for a red stone).
            $user = 'The store calls this product: '.mb_substr((string) $image->title, 0, 300).'
'.($language === 'Hebrew' ? '' : "The store's language: {$language}.\n").'Describe this product photo.';
            $id = $image->id;
            $provider = $reader->value;
            $tasks[$id] = static fn (): array => self::readOne($shopId, $id, $provider, $model, $system, $user, $maxOutput, $in, $out);
        }

        $parallel = max(1, (int) Settings::get('retrieval.caption_parallel'));
        if ($parallel === 1) {
            return array_map(fn (callable $task): array => $task(), $tasks);
        }

        $read = [];
        foreach (array_chunk($tasks, $parallel, true) as $chunk) {
            try {
                $read += Concurrency::run($chunk);
            } catch (Throwable $e) {
                // the side-by-side reading itself failed (no process could start): these pictures wait for the next run
                report($e);
            }
        }

        return $read;
    }

    /**
     * One picture fetched and read, where it runs (a process of its own, or here): read, not there to read, refused by
     * the model (passed over for good), or an error of the moment (tried again next time). What it cost is counted by
     * the run that asked.
     *
     * @return array{outcome: string, data?: array<string, mixed>, input?: int, output?: int, cost?: float}
     */
    public static function readOne(string $shopId, int|string $imageId, string $provider, string $model, string $system, string $user, int $maxOutput, float $in, float $out): array
    {
        try {
            return app(TenantContext::class)->run($shopId, function () use ($imageId, $provider, $model, $system, $user, $maxOutput, $in, $out): array {
                $image = RetrievalImage::query()->find($imageId);
                $picture = $image === null ? null : app(self::class)->fetch($image);

                if ($picture === null) {
                    return ['outcome' => 'no_picture'];
                }

                try {
                    $reply = app(VisionModel::class)->jsonWithImage(AiProviderName::from($provider), $model, $system, $user, $picture['mime'], $picture['data'], $maxOutput, 'low');
                } catch (ModelCallFailed $e) {
                    report($e);

                    return ['outcome' => 'refused'];
                }

                return ['outcome' => 'read', 'data' => $reply->data, 'input' => $reply->inputTokens, 'output' => $reply->outputTokens, 'cost' => $reply->costUsd($in, $out)];
            });
        } catch (Throwable $e) {
            report($e);

            return ['outcome' => 'error'];
        }
    }

    /** @return array{mime: string, data: string}|null */
    private function fetch(RetrievalImage $image): ?array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->get(PictureAddress::fetchable($image->image_url));
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
