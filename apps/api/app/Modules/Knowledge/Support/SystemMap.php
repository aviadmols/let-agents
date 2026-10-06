<?php

namespace App\Modules\Knowledge\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsOrderImport;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Retrieval\Enums\MatchStatus;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalMatch;
use App\Modules\Retrieval\Models\RetrievalMatchRequest;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * What the panel's map draws, counted from the tables: how a shop's stores are built, how its
 * products are matched, and how one product's page is put together. Read-only, inside the
 * shop's tenant context.
 *
 * It names other modules' actions by their run strings, never by their classes: this screen
 * describes the work, it does not depend on how the work is done.
 */
final class SystemMap
{
    public const INDEX_ACTION = 'retrieval.build_index';

    public const MATCH_ACTION = 'retrieval.match_products';

    public const RELATIONS_ACTION = 'enrichment.compute_relations';

    /** The stages BuildPageBank traces, in order. */
    public const PAGE_STAGES = ['built', 'allowed', 'learned', 'curated'];

    /** @return array<string, mixed> */
    public static function index(): array
    {
        $model = (string) Settings::get('retrieval.embedding_model');
        $chunks = RetrievalChunk::query()
            ->select('source')
            ->selectRaw('count(*) as chunks')
            ->selectRaw('count(distinct source_id) as documents')
            ->selectRaw('sum(case when embedding_model = ? and embedding is not null then 1 else 0 end) as embedded', [$model])
            ->selectRaw('sum(case when embedding_model is not null and embedding_model != ? then 1 else 0 end) as stale', [$model])
            ->selectRaw('avg(length(text)) as average')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $content = CatalogContent::query()->active()->select('type', DB::raw('count(*) as n'))->groupBy('type')->pluck('n', 'type');
        $orders = AnalyticsOrder::query()->select('source', DB::raw('count(*) as n'))->groupBy('source')->pluck('n', 'source');
        $import = AnalyticsOrderImport::query()->first();

        $sources = [];

        foreach (array_unique(['product', 'content', 'purchases', ...$chunks->keys()->all()]) as $key) {
            $row = $chunks->get($key);
            $sources[$key] = [
                'documents' => (int) ($row->documents ?? 0),
                'chunks' => (int) ($row->chunks ?? 0),
                'embedded' => (int) ($row->embedded ?? 0),
                'stale' => (int) ($row->stale ?? 0),
                'average' => (int) round((float) ($row->average ?? 0)),
            ];
        }

        $total = array_sum(array_column($sources, 'chunks'));
        $embedded = array_sum(array_column($sources, 'embedded'));

        return [
            'store' => [
                'products' => CatalogProduct::query()->active()->count(),
                'pages' => (int) ($content['page'] ?? 0),
                'posts' => (int) $content->except('page')->sum(),
                'orders_live' => (int) ($orders['live'] ?? 0),
                'orders_history' => (int) ($orders['history'] ?? 0),
            ],
            'history' => $import === null ? null : [
                'expected' => $import->expected,
                'received' => $import->received,
                'stored' => $import->stored,
                'refused' => $import->refused,
                'oldest' => $import->oldest_ordered_at?->toDateString(),
                'finished' => $import->finished_at?->toDateTimeString(),
                'started' => $import->started_at?->toDateTimeString(),
            ],
            'sources' => $sources,
            'chunks' => $total,
            'embedded' => $embedded,
            'pending' => $total - $embedded,
            'model' => (string) Settings::get('retrieval.embedding_provider').' · '.$model,
            'dimensions' => RetrievalChunk::query()->where('embedding_model', $model)->whereNotNull('dimensions')->value('dimensions'),
            'last' => self::lastRun(self::INDEX_ACTION),
            'spend' => self::spend(),
        ];
    }

    /** @return array<string, mixed> */
    public static function matching(): array
    {
        $requests = RetrievalMatchRequest::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $verdicts = RetrievalMatch::query()->select('status', 'kind', 'rejected_because', DB::raw('count(*) as n'))
            ->groupBy('status', 'kind', 'rejected_because')->get();
        $relations = EnrichmentProductRelation::query()->select('source', 'kind', DB::raw('count(*) as n'))->groupBy('source', 'kind')->get();
        $products = CatalogProduct::query()->active()->count();
        $guides = CatalogContent::query()->active()->whereNotNull('product_external_ids')->pluck('product_external_ids')
            ->filter(fn ($ids): bool => count((array) $ids) >= 2)->count();

        $bySource = [];

        foreach ($relations as $row) {
            $bySource[$row->source][$row->kind->value] = (int) $row->n;
        }

        uksort($bySource, fn (string $a, string $b): int => array_sum($bySource[$b]) <=> array_sum($bySource[$a]));

        $accepted = $verdicts->where('status', MatchStatus::Accepted);
        $rejected = $verdicts->where('status', MatchStatus::Rejected);

        return [
            'evidence' => [
                'orders' => AnalyticsOrder::query()->where('ordered_at', '>=', now()->subDays((int) Settings::get('retrieval.purchase_window_days')))->count(),
                'vectors' => RetrievalChunk::query()->where('source', 'product')->where('embedding_model', (string) Settings::get('retrieval.embedding_model'))->count(),
                'guides' => $guides,
            ],
            'model' => (string) Settings::get('retrieval.match_provider').' · '.(string) Settings::get('retrieval.match_model'),
            'products' => $products,
            'requests' => [
                'answered' => (int) ($requests['answered'] ?? 0),
                'too_few' => (int) ($requests['too_few'] ?? 0),
                'failed' => (int) ($requests['failed'] ?? 0),
                'never' => max(0, $products - (int) $requests->sum()),
            ],
            'accepted' => [
                'complement' => (int) $accepted->filter(fn ($r): bool => $r->kind->value === 'complement')->sum('n'),
                'alternative' => (int) $accepted->filter(fn ($r): bool => $r->kind->value === 'alternative')->sum('n'),
            ],
            'rejected' => $rejected->groupBy('rejected_because')->map(fn ($rows): int => (int) $rows->sum('n'))->sortDesc()->all(),
            'relations' => $bySource,
            'last' => self::lastRun(self::MATCH_ACTION),
            'relations_last' => self::lastRun(self::RELATIONS_ACTION),
            'spend' => self::spend(),
        ];
    }

    /**
     * One product's whole matching trail: what code offered, from where, with what evidence; what
     * the model picked and why; what code decided; and the relations the page now reads.
     *
     * @return array<string, mixed>|null
     */
    public static function productMatching(string $productId): ?array
    {
        $product = CatalogProduct::query()->find($productId);

        if ($product === null) {
            return null;
        }

        $request = RetrievalMatchRequest::query()->where('product_id', $productId)->first();
        $verdicts = $request === null ? collect() : RetrievalMatch::query()->where('request_id', $request->id)->get()->keyBy('related_product_id');
        $ids = array_merge(array_column($request?->candidates ?? [], 'product_id'), $verdicts->keys()->filter()->all());
        $relations = EnrichmentProductRelation::query()->where('product_id', $productId)->orderBy('kind')->orderByDesc('score')->get();
        $titles = CatalogProduct::query()->whereIn('id', array_unique([...$ids, ...$relations->pluck('related_product_id')->all()]))->pluck('title', 'id');

        $candidates = array_map(function (array $c) use ($verdicts, $titles): array {
            $verdict = $verdicts->get($c['product_id']);

            return [
                'ref' => $c['ref'],
                'title' => $titles[$c['product_id']] ?? $c['product_id'],
                'sources' => $c['sources'],
                'signals' => $c['signals'],
                'picked' => $verdict?->kind->value,
                'status' => $verdict?->status->value,
                'because' => $verdict?->rejected_because,
                'why' => $verdict?->reason,
            ];
        }, $request?->candidates ?? []);

        return [
            'product' => ['id' => $product->id, 'external_id' => $product->external_id, 'title' => $product->title],
            'request' => $request === null ? null : [
                'status' => $request->status->value,
                'model' => $request->model,
                'asked_at' => $request->asked_at?->toDateTimeString(),
                'cost' => (float) $request->cost_usd,
                'error' => $request->error,
            ],
            'candidates' => $candidates,
            'invented' => $request === null ? 0 : RetrievalMatch::query()->where('request_id', $request->id)->whereNull('related_product_id')->count(),
            'relations' => $relations->map(fn (EnrichmentProductRelation $r): array => [
                'kind' => $r->kind->value,
                'title' => $titles[$r->related_product_id] ?? $r->related_product_id,
                'source' => $r->source,
                'also' => $r->reasons['also'] ?? [],
                'score' => $r->score,
            ])->all(),
        ];
    }

    /**
     * How the page bank changed from stage to stage: per section, the items that came in, the
     * items that went, and where the section ended up.
     *
     * @param  list<array{stage: string, sections: list<array<string, mixed>>}>  $trace
     * @return list<array{stage: string, sections: list<array<string, mixed>>}>
     */
    public static function pageStages(array $trace): array
    {
        $stages = [];
        $before = null;

        foreach ($trace as $step) {
            $previous = $before === null ? [] : collect($before['sections'])->keyBy('candidate');
            $sections = [];

            foreach ($step['sections'] as $position => $section) {
                $was = $previous[$section['candidate']] ?? null;
                $had = $was === null ? [] : array_column($was['items'], 'id');
                $has = array_column($section['items'], 'id');
                $wasAt = $was === null ? null : array_search($section['candidate'], array_column($before['sections'], 'candidate'), true);

                $sections[] = $section + [
                    'position' => $position + 1,
                    'moved' => $wasAt === false || $wasAt === null ? 0 : (int) $wasAt - $position,
                    'new' => $before !== null && $was === null,
                    'added' => $before === null ? [] : array_values(array_diff($has, $had)),
                    'dropped' => $was === null ? [] : array_values(array_filter($was['items'], fn (array $i): bool => ! in_array($i['id'], $has, true))),
                ];
            }

            $gone = $before === null ? [] : array_values(array_filter(
                $before['sections'],
                fn (array $s): bool => ! in_array($s['candidate'], array_column($step['sections'], 'candidate'), true),
            ));

            $stages[] = ['stage' => $step['stage'], 'sections' => $sections, 'gone' => array_column($gone, 'title')];
            $before = $step;
        }

        return $stages;
    }

    /** @return array<string, mixed>|null */
    private static function lastRun(string $action): ?array
    {
        $shopId = app(TenantContext::class)->id();
        $run = $shopId === null ? null : Run::query()->forShop($shopId)->where('action', $action)->latest('started_at')->first();

        return $run === null ? null : [
            'status' => $run->status->value,
            'summary' => $run->summary(),
            'at' => $run->started_at?->toDateTimeString(),
            'took_ms' => $run->duration_ms,
            'cost' => (float) $run->cost_usd,
            'output' => $run->output ?? [],
        ];
    }

    /** @return array{spent: float, cap: float, share: float} */
    private static function spend(): array
    {
        $guard = app(SpendGuard::class);
        $cap = $guard->cap();

        return [
            'spent' => round($guard->spentThisMonth(), 2),
            'cap' => $cap,
            'share' => (float) Settings::get('retrieval.match_max_share_of_cap'),
        ];
    }
}
