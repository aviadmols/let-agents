<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * The count of what is known about one shop, taken the same way every time.
 *
 * The night writes it down and the screen reads it live, and both have to agree, so neither of
 * them counts anything itself. Every method here expects to be called inside the shop's own
 * scope; none of them takes a shop id, because none of them should be able to reach another one.
 */
final class Census
{
    /** The window the reward signals are counted over. */
    public const SIGNAL_DAYS = 30;

    /** A signal seen fewer times than this over the window cannot be learned from. */
    public const SIGNAL_ALIVE = 30;

    /** The steps whose last run says whether what is known is fresh. */
    public const STEPS = [
        'catalog.sync', 'enrichment.read_in_code', 'enrichment.read_promises',
        'enrichment.read_content', 'enrichment.compute_rankings', 'enrichment.compute_relations',
        'enrichment.audit_content', 'analytics.compute_scores', 'analytics.compute_popularity',
    ];

    /**
     * How much of what was scanned the system has something to say about.
     *
     * @return array<string, mixed>
     */
    public static function coverage(): array
    {
        $products = CatalogProduct::query()->whereNull('removed_at')->count();
        $articles = CatalogContent::query()->whereNull('removed_at')->where('type', '!=', 'page')->count();
        $pages = CatalogContent::query()->whereNull('removed_at')->where('type', 'page')->count();

        $withCode = self::productsRead(FactOrigin::Code);
        $withModel = self::productsRead(FactOrigin::Model);

        $byKind = EnrichmentFact::query()
            ->where('status', FactStatus::Approved)
            ->whereNotNull('product_id')
            ->select('kind', DB::raw('count(distinct product_id) as products'))
            ->groupBy('kind')
            ->pluck('products', 'kind')
            ->all();

        $takeaways = EnrichmentFact::query()
            ->whereNotNull('content_id')
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('content_id');

        return [
            'catalog' => ['products' => $products, 'articles' => $articles, 'pages' => $pages],
            'read_in_code' => ['products' => $withCode, 'share' => self::share($withCode, $products)],
            'read_by_model' => ['products' => $withModel, 'share' => self::share($withModel, $products)],
            'decided_by_people' => ['facts' => EnrichmentFact::query()->whereNotNull('decided_by')->count()],
            'waiting_for_a_person' => ['facts' => EnrichmentFact::query()->where('status', FactStatus::NeedsPerson)->count()],
            'by_kind' => array_map('intval', $byKind),
            'articles_with_points' => ['articles' => $takeaways, 'share' => self::share($takeaways, $articles)],
            'computed' => [
                'rankings' => EnrichmentRanking::query()->count(),
                'relations' => EnrichmentProductRelation::query()->count(),
                'popular' => AnalyticsPopularity::query()->where('popular', true)->count(),
            ],
            'questions' => [
                'answered' => AssistantAnswer::query()->where('outcome', AssistantAnswer::ANSWERED)->count(),
                'no_info' => AssistantAnswer::query()->where('outcome', AssistantAnswer::NO_INFO)->count(),
                'refused' => AssistantAnswer::query()->where('outcome', AssistantAnswer::OUT_OF_SCOPE)->count(),
            ],
            // One number for the top of the screen. A shop is measured on its products; a site
            // that sells nothing is measured on its articles, because otherwise the honest answer
            // for a magazine that has been read from end to end is nought per cent.
            'known_share' => $products === 0
                ? self::share($takeaways, $articles)
                : self::share(max($withCode, $withModel), $products),
            'known_share_of' => $products === 0 ? 'articles' : 'products',
        ];
    }

    /**
     * When each step last finished, so an old reading can be told from a fresh one.
     *
     * Runs are not shop-scoped by the model, so this one does take a shop.
     *
     * @return array<string, ?string>
     */
    public static function freshness(string $shopId): array
    {
        $last = Run::query()
            ->where('shop_id', $shopId)
            ->whereIn('action', self::STEPS)
            ->where('status', 'succeeded')
            ->select('action', DB::raw('max(finished_at) as at'))
            ->groupBy('action')
            ->pluck('at', 'action')
            ->all();

        $freshness = [];

        foreach (self::STEPS as $action) {
            $at = $last[$action] ?? null;
            $freshness[$action] = $at === null ? null : (string) $at;
        }

        return $freshness;
    }

    /**
     * What is missing, each with the step that would close it.
     *
     * The most useful part of the screen: the only part that says what to do next. A gap with no
     * fix is one no step can close — the shop never wrote the answer down.
     *
     * @param  array<string, mixed>  $coverage
     * @return list<array{key: string, count: int, fix: ?string}>
     */
    public static function gaps(array $coverage): array
    {
        $gaps = [];

        $withAnything = EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('product_id');
        $noFacts = max(0, (int) $coverage['catalog']['products'] - $withAnything);

        if ($noFacts > 0) {
            $gaps[] = ['key' => 'products_without_facts', 'count' => $noFacts, 'fix' => 'enrichment.read_in_code'];
        }

        $articlesWithout = max(0, (int) $coverage['catalog']['articles'] - (int) $coverage['articles_with_points']['articles']);

        if ($articlesWithout > 0) {
            $gaps[] = ['key' => 'articles_without_points', 'count' => $articlesWithout, 'fix' => 'enrichment.audit_content'];
        }

        // A shop that shares no pages cannot have shop-level promises: returns, shipping and
        // warranty are written on pages.
        if ((int) $coverage['catalog']['pages'] === 0) {
            $gaps[] = ['key' => 'no_pages_shared', 'count' => 0, 'fix' => null];
        }

        if ((int) $coverage['questions']['no_info'] > 0) {
            $gaps[] = ['key' => 'questions_without_an_answer', 'count' => (int) $coverage['questions']['no_info'], 'fix' => null];
        }

        if ((int) $coverage['computed']['relations'] === 0 && (int) $coverage['catalog']['products'] > 0) {
            $gaps[] = ['key' => 'no_relations', 'count' => 0, 'fix' => 'enrichment.compute_relations'];
        }

        if ((int) $coverage['waiting_for_a_person']['facts'] > 0) {
            $gaps[] = ['key' => 'facts_waiting_for_a_person', 'count' => (int) $coverage['waiting_for_a_person']['facts'], 'fix' => null];
        }

        return $gaps;
    }

    /**
     * What the shopper is shown, in the order the learning put it.
     *
     * @return array<string, list<string>>
     */
    public static function arrangement(): array
    {
        return ['learned_order' => array_values(array_unique(
            AnalyticsScore::query()
                ->where('scope', AnalyticsScore::SCOPE_MODULE)
                ->orderByDesc('score')
                ->pluck('candidate')
                ->all(),
        ))];
    }

    /**
     * What the learning is being judged on, and whether each signal is alive enough to judge by.
     *
     * A system that learns from a signal nobody sends is optimising noise, so this says out loud
     * which one it is actually following today.
     *
     * @return array<string, mixed>
     */
    public static function signals(): array
    {
        $since = now()->subDays(self::SIGNAL_DAYS);

        // What the widget learns from: adds from the store's search are the search's.
        $counts = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', AnalyticsEvent::SOURCE_SEARCH))
            ->select('type', DB::raw('count(*) as c'))
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        $signals = [
            'exposure' => (int) ($counts['exposure'] ?? 0),
            'open' => (int) ($counts['open'] ?? 0),
            'click' => (int) ($counts['click'] ?? 0),
            'dismiss' => (int) ($counts['dismiss'] ?? 0),
            'add_to_cart' => (int) ($counts['add_to_cart'] ?? 0),
            'order' => AnalyticsOrder::query()->where('ordered_at', '>=', $since)->count(),
        ];

        $verdicts = [];

        foreach ($signals as $name => $count) {
            $verdicts[$name] = match (true) {
                $count === 0 => 'missing',
                $count < self::SIGNAL_ALIVE => 'weak',
                default => 'alive',
            };
        }

        // The strongest signal that is alive, in the order of how much it is worth knowing.
        $best = 'none';

        foreach (['order', 'add_to_cart', 'click', 'open'] as $name) {
            if ($verdicts[$name] === 'alive') {
                $best = $name;

                break;
            }
        }

        return ['window_days' => self::SIGNAL_DAYS, 'counts' => $signals, 'verdicts' => $verdicts, 'optimising_for' => $best];
    }

    private static function productsRead(FactOrigin $origin): int
    {
        return EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('origin', $origin)
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('product_id');
    }

    private static function share(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : (int) round(100 * $part / $whole);
    }
}
