<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
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

        foreach (CatalogProduct::query()->active()->whereNotNull('image_url')->orderBy('id')->lazy(200) as $product) {
            $url = (string) $product->image_url;

            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $stats['products']++;
            $hash = hash('sha256', $url);
            $row = RetrievalImage::query()->firstOrNew(['product_id' => $product->id], ['shop_id' => $shopId]);
            $row->fill(['external_id' => $product->external_id, 'title' => mb_substr($product->title, 0, 500)]);

            if ($row->exists && $row->url_hash === $hash && $row->embedding_model === $choice->model && $row->embedding !== null) {
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

                $images[] = $image;
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
                $stats['stopped'] = $e->reason;
                break;
            }

            $cost = count($images) * $price;
            $run->usage($choice->providerName, $choice->model, $embeddings->inputTokens, 0, 0, $cost);
            $stats['cost_usd'] += $cost;

            foreach ($rows as $i => $row) {
                RetrievalImage::query()->whereKey($row->id)->update([
                    'embedding' => VectorSearch::column($embeddings->vectors[$i]),
                    'embedding_model' => $choice->model,
                    'dimensions' => count($embeddings->vectors[$i]),
                    'error' => null,
                    'embedded_at' => now(),
                ]);
            }

            $stats['embedded'] += count($rows);
        }

        if ($stats['stopped'] === null && count($todo) > $limit) {
            $stats['stopped'] = 'more';
        }

        $stats['pending'] = RetrievalImage::query()->whereNull('error')
            ->where(fn ($q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $choice->model))
            ->count();
        $stats['cost_usd'] = round($stats['cost_usd'], 6);

        $run->output($stats + ['provider' => $choice->providerName, 'model' => $choice->model])
            ->summary('retrieval::runs.images_indexed', [
                'products' => (string) $stats['products'],
                'embedded' => (string) $stats['embedded'],
                'kept' => (string) $stats['kept'],
                'pending' => (string) $stats['pending'],
            ]);
    }

    /** @return array{mime: string, data: string}|null */
    private function fetch(RetrievalImage $row): ?array
    {
        $maxBytes = (int) Settings::get('retrieval.image_max_kb') * 1024;

        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->get($row->image_url);
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
            default => ['mime' => $mime, 'data' => $body],
        };
    }

    private function failed(RetrievalImage $row, string $reason): null
    {
        RetrievalImage::query()->whereKey($row->id)->update(['error' => $reason]);

        return null;
    }
}
