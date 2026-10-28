<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalGalleryImage;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\PictureAddress;
use App\Modules\Retrieval\Support\PictureTrim;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Each product's main picture, given a vector in the same space as words.
 *
 *   1. compare   a product whose picture address and model are unchanged keeps its vector; a
 *                product the store stopped publishing takes its row with it
 *   2. fetch     the picture from the store, refused when it is too big or not a picture
 *   3. embed     in batches, each asking SpendGuard first and priced per picture
 *
 * What this makes possible, with no model call later: products that look alike (an alternative
 * a text cannot describe: a cut, a pattern, a shade), and pictures found by words ("חולצת פסים").
 * Off by default (retrieval.image_index); worth turning on where the picture is the product.
 */
final class BuildImageIndex
{
    public const AGENT = 'retrieval.image_indexer';

    public const ACTION = 'retrieval.build_image_index';

    private const BATCH = 16;

    /** Seconds one run scans before it stops and leaves the rest for the next part. */
    private const TIME_BUDGET = 1200;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ImageEmbedder $embedder,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->locked($run, $shopId)),
        );
    }

    /** Two scans of the same shop at once would fetch and pay for the same pictures twice. */
    private function locked(RunContext $run, string $shopId): void
    {
        $lock = Cache::lock('retrieval:images:'.$shopId, self::TIME_BUDGET + 300);

        if (! $lock->get()) {
            $run->output(['stopped' => 'already_running'])->summary('retrieval::runs.images_busy');

            return;
        }

        try {
            $this->build($run, $shopId);
        } finally {
            $lock->release();
        }
    }

    private function build(RunContext $run, string $shopId): void
    {
        $choice = ModelChoice::for('image');
        $stats = ['products' => 0, 'embedded' => 0, 'kept' => 0, 'skipped' => 0, 'removed' => 0, 'pending' => 0, 'stopped' => null, 'cost_usd' => 0.0];

        $stats['removed'] = RetrievalImage::query()
            ->whereNotIn('product_id', CatalogProduct::query()->active()->whereNotNull('image_url')->select('id'))
            ->delete();

        $todo = [];
        // What the provider said when it refused a picture asked alone: a key, a quota, an outage.
        $refused = null;

        foreach (CatalogProduct::query()->active()->whereNotNull('image_url')->orderBy('id')->lazy(200) as $product) {
            $url = (string) $product->image_url;

            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $stats['products']++;
            $hash = hash('sha256', $url);
            $row = RetrievalImage::query()->firstOrNew(['product_id' => $product->id], ['shop_id' => $shopId]);
            $row->fill(['external_id' => $product->external_id, 'title' => mb_substr($product->title, 0, 500)]);

            if ($row->exists && $row->url_hash === $hash && $row->embedding_model === $choice->model && $row->embedding !== null && (int) $row->prep === PictureTrim::VERSION) {
                $row->isDirty() && $row->save();
                $stats['kept']++;

                continue;
            }

            if ($row->url_hash !== $hash) {
                $row->fill(['image_url' => $url, 'url_hash' => $hash, 'embedding' => null, 'embedding_model' => null, 'dimensions' => null, 'error' => null, 'embedded_at' => null]);
            }

            $row->save();
            $todo[] = $row;
        }

        if ($choice->provider === null || $choice->model === '') {
            $stats['stopped'] = 'unknown_provider';
        }

        // A part at a time: the rest is left for the next part, which the job queues at once.
        $limit = min((int) Settings::get('retrieval.max_images_per_run', $shopId), (int) Settings::get('retrieval.images_per_part'));
        $price = (float) Settings::get('retrieval.image_usd_per_image');
        $dimensions = (int) Settings::get('retrieval.image_dimensions') ?: null;

        $started = microtime(true);

        foreach (array_chunk($stats['stopped'] === null ? array_slice($todo, 0, $limit) : [], self::BATCH) as $batch) {
            // Stopping in time keeps what was done; a worker that kills the run keeps nothing of the run's report.
            if (microtime(true) - $started > self::TIME_BUDGET) {
                $stats['stopped'] = 'time_budget';
                break;
            }

            $images = [];
            $rows = [];

            foreach ($batch as $row) {
                $image = $this->fetch($row);

                if ($image === null) {
                    $stats['skipped']++;

                    continue;
                }

                if (self::sameAsBefore($row, $image, $choice)) {
                    $stats['kept']++;

                    continue;
                }

                $images[] = ['mime' => $image['mime'], 'data' => $image['data']];
                $rows[] = $row;
            }

            if ($images === []) {
                continue;
            }

            try {
                $this->spend->assertCanSpend(count($images) * $price);
                $embeddings = $this->embedder->embedImages($choice->provider, $choice->model, $images, $dimensions);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                // One picture the provider will not read fails the whole batch. Each is asked
                // alone: what fails alone is marked unreadable and the rest go on; only when every
                // one fails (a key, a quota, an outage) does the scan stop.
                report($e);
                $refused = $e->reason;
                [$vectors, $kept] = $this->oneByOne($choice, $images, $rows, $dimensions);

                // Several refused, every one alone: not the pictures (a key, a quota, an outage).
                if ($kept === [] && count($rows) > 1) {
                    $stats['stopped'] = $e->reason;
                    break;
                }

                if ($kept === []) {
                    $stats['skipped']++;

                    continue;
                }

                $stats['skipped'] += count($rows) - count($kept);
                $rows = $kept;
                $embeddings = new Embeddings($vectors, 0);
                $images = array_slice($images, 0, count($kept));
            }

            $cost = count($images) * $price;
            $run->usage($choice->providerName, $choice->model, $embeddings->inputTokens, 0, 0, $cost);
            $stats['cost_usd'] += $cost;

            foreach ($rows as $i => $row) {
                RetrievalImage::query()->whereKey($row->id)->update([
                    'embedding' => VectorSearch::column($embeddings->vectors[$i]),
                    'prep' => PictureTrim::VERSION,
                    'embedding_model' => $choice->model,
                    'dimensions' => count($embeddings->vectors[$i]),
                    'error' => null,
                    'embedded_at' => now(),
                ]);
            }

            $stats['embedded'] += count($rows);
        }

        // Each product's other pictures, once every main picture is done, in what is left of this part.
        if ($stats['stopped'] === null && count($todo) <= $limit) {
            $this->gallery($run, $shopId, $choice, $stats, $limit - $stats['embedded'] - $stats['skipped'], $started, $price, $dimensions);
        }

        if ($stats['stopped'] === null && count($todo) > $limit) {
            $stats['stopped'] = 'more';
        }

        $stats['pending'] = RetrievalImage::query()->whereNull('error')
            ->where(fn ($q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $choice->model))
            ->count();
        $stats['cost_usd'] = round($stats['cost_usd'], 6);

        $run->output($stats + ['provider' => $choice->providerName, 'model' => $choice->model]);
        $counts = [
            'products' => (string) $stats['products'],
            'embedded' => (string) $stats['embedded'],
            'kept' => (string) $stats['kept'],
            'pending' => (string) $stats['pending'],
        ];

        // A scan that never reached the picture model is a failed run, never "done" with no picture given a
        // vector (and no camera in the search): no provider set, the provider refusing a whole batch, or
        // refusing every picture of a shop that has none yet. The next part, the resume or the night tries again.
        if ($stats['stopped'] === 'unknown_provider' && $todo !== []) {
            $run->fail('retrieval::runs.images_no_provider', $counts);
        } elseif ($refused !== null && ($stats['stopped'] === $refused || $stats['embedded'] + $stats['kept'] === 0)) {
            $run->fail('retrieval::runs.images_refused', $counts + ['reason' => $refused]);
        } else {
            $run->summary('retrieval::runs.images_indexed', $counts);
        }
    }

    /**
     * A product's other pictures (retrieval.gallery_pictures of them, after the main one), each with
     * its own vector, so a photo taken from the side finds the store's side view. Kept while the
     * address is the same; an address the provider would not read stays unread until it changes.
     *
     * @param  array<string, mixed>  $stats
     */
    private function gallery(RunContext $run, string $shopId, ModelChoice $choice, array &$stats, int $room, float $started, float $price, ?int $dimensions): void
    {
        $per = (int) Settings::get('retrieval.gallery_pictures', $shopId);
        $stats += ['gallery_embedded' => 0, 'gallery_pending' => 0];

        // A product gone, or more pictures kept than the shop now wants: those rows go.
        RetrievalGalleryImage::query()
            ->where(fn ($q) => $q->where('position', '>', $per)->orWhereNotIn('product_id', CatalogProduct::query()->active()->select('id')))
            ->delete();

        if ($per < 1 || $choice->provider === null || $choice->model === '') {
            return;
        }

        $todo = [];

        foreach (CatalogProduct::query()->active()->whereNotNull('image_url')->orderBy('id')->lazy(200) as $product) {
            $urls = [];

            foreach ((array) ($product->payload['images'] ?? []) as $image) {
                $url = is_array($image) ? (string) ($image['url'] ?? '') : '';

                if (preg_match('#^https?://#i', $url) === 1 && $url !== $product->image_url && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }

            $urls = array_slice($urls, 0, $per);
            RetrievalGalleryImage::query()->where('product_id', $product->id)->where('position', '>', count($urls))->delete();

            foreach ($urls as $at => $url) {
                $hash = hash('sha256', $url);
                $row = RetrievalGalleryImage::query()->firstOrNew(['product_id' => $product->id, 'position' => $at + 1], ['shop_id' => $shopId]);
                $row->fill(['external_id' => $product->external_id, 'title' => mb_substr($product->title, 0, 500)]);
                $same = $row->exists && $row->url_hash === $hash;

                if ($same && ($row->error !== null || ($row->embedding_model === $choice->model && $row->embedding !== null && (int) $row->prep === PictureTrim::VERSION))) {
                    $row->isDirty() && $row->save();

                    continue;
                }

                if (! $same) {
                    $row->fill(['image_url' => $url, 'url_hash' => $hash, 'embedding' => null, 'embedding_model' => null, 'dimensions' => null, 'error' => null, 'embedded_at' => null]);
                }

                $row->save();
                $todo[] = $row;
            }
        }

        foreach (array_chunk(array_slice($todo, 0, max(0, $room)), self::BATCH) as $batch) {
            if (microtime(true) - $started > self::TIME_BUDGET) {
                $stats['stopped'] = 'time_budget';
                break;
            }

            $images = [];
            $rows = [];

            foreach ($batch as $row) {
                if (($image = $this->fetch($row)) !== null && ! self::sameAsBefore($row, $image, $choice)) {
                    $images[] = ['mime' => $image['mime'], 'data' => $image['data']];
                    $rows[] = $row;
                }
            }

            if ($images === []) {
                continue;
            }

            try {
                $this->spend->assertCanSpend(count($images) * $price);
                $embeddings = $this->embedder->embedImages($choice->provider, $choice->model, $images, $dimensions);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                // The gallery is extra: a refusal waits for the next scan rather than marking pictures.
                report($e);
                $stats['stopped'] = $e->reason;
                break;
            }

            $cost = count($images) * $price;
            $run->usage($choice->providerName, $choice->model, $embeddings->inputTokens, 0, 0, $cost);
            $stats['cost_usd'] += $cost;

            foreach ($rows as $i => $row) {
                RetrievalGalleryImage::query()->whereKey($row->id)->update([
                    'embedding' => VectorSearch::column($embeddings->vectors[$i]),
                    'prep' => PictureTrim::VERSION,
                    'embedding_model' => $choice->model,
                    'dimensions' => count($embeddings->vectors[$i]),
                    'error' => null,
                    'embedded_at' => now(),
                ]);
            }

            $stats['gallery_embedded'] += count($rows);
        }

        $stats['gallery_pending'] = RetrievalGalleryImage::query()->whereNull('error')
            ->where(fn ($q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $choice->model))
            ->count();

        if ($stats['stopped'] === null && count($todo) > max(0, $room)) {
            $stats['stopped'] = 'more';
        }
    }

    /**
     * The pictures of a refused batch, each asked alone.
     *
     * @param  list<array{mime: string, data: string}>  $images
     * @param  list<RetrievalImage>  $rows
     * @return array{0: list<list<float>>, 1: list<RetrievalImage>} the vectors and the rows they belong to
     */
    private function oneByOne(object $choice, array $images, array $rows, ?int $dimensions): array
    {
        $vectors = [];
        $kept = [];
        $failed = 0;

        foreach ($images as $i => $image) {
            try {
                $vectors[] = $this->embedder->embedImages($choice->provider, $choice->model, [$image], $dimensions)->vectors[0];
                $kept[] = $rows[$i];
            } catch (ModelCallFailed $e) {
                $failed++;
                $this->failed($rows[$i], 'refused');
            }
        }

        // Several, every one refused alone, is not a picture problem: leave them for the next scan.
        if ($kept === [] && $failed > 1) {
            RetrievalImage::query()->whereIn('id', array_map(fn (RetrievalImage $r): string => (string) $r->id, $rows))->where('error', 'refused')->update(['error' => null]);
        }

        return [$vectors, $kept];
    }

    /** @return array{mime: string, data: string}|null */
    private function fetch(RetrievalImage|RetrievalGalleryImage $row): ?array
    {
        $maxBytes = (int) Settings::get('retrieval.image_max_kb') * 1024;

        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->get(PictureAddress::fetchable($row->image_url));
        } catch (ConnectionException) {
            return $this->failed($row, 'unreachable');
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        return match (true) {
            ! $response->successful() => $this->failed($row, 'unreachable'),
            ! in_array($mime, self::MIMES, true) => $this->failed($row, 'not_image'),
            strlen($body) > $maxBytes => $this->failed($row, 'too_big'),
            $body === '' => $this->failed($row, 'not_image'),
            default => self::prepared($mime, $body),
        };
    }

    /**
     * The picture as it is compared: without the plain background around the product, when there is
     * one to trim; as it came otherwise.
     *
     * @return array{mime: string, data: string, background: string|null}
     */
    private static function prepared(string $mime, string $body): array
    {
        $cut = PictureTrim::cut($body);

        return $cut === null ? ['mime' => $mime, 'data' => $body, 'background' => null] : ['mime' => 'image/jpeg', 'data' => $cut['data'], 'background' => $cut['background']];
    }

    /**
     * A picture embedded under an older trimming that is prepared just the same now: its vector stands, only its version
     * moves, and no model is asked (a new trimming re-embeds only the pictures it cuts differently).
     *
     * @param  array{mime: string, data: string, background: string|null}  $image
     */
    private static function sameAsBefore(RetrievalImage|RetrievalGalleryImage $row, array $image, ModelChoice $choice): bool
    {
        $prep = (int) $row->prep;

        if ($row->embedding === null || $row->embedding_model !== $choice->model || $prep >= PictureTrim::VERSION || PictureTrim::changedSince($prep, $image['background'])) {
            return false;
        }

        $row::query()->whereKey($row->id)->update(['prep' => PictureTrim::VERSION]);

        return true;
    }

    private function failed(RetrievalImage|RetrievalGalleryImage $row, string $reason): null
    {
        $row::query()->whereKey($row->id)->update(['error' => $reason]);

        return null;
    }
}
