<?php

namespace App\Modules\Improvement\Support;

use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Actions\DecideProposal;
use App\Modules\Improvement\Filament\Merchant\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Tenancy\Models\Shop;
use Throwable;

/**
 * The review's proposals on Today's "Waiting for you": a word that found nothing and the word it means, approved in
 * one tap ("Quick win", with the products shoppers will see); a question shoppers ask with a drafted answer to check
 * ("Asked often"); a page or post the store lacks ("Bigger idea"). Ranked by the searches and questions behind each.
 * What they did once approved is the search's to count (SearchFixesToday). No model here.
 */
final class ImprovementToday implements TodayImprovements
{
    public function waiting(Shop $shop): array
    {
        $url = SiteSuggestions::getUrl(panel: 'merchant', tenant: $shop);
        $fixes = [];

        foreach (ImprovementProposal::query()->where('status', ImprovementProposal::PENDING)->latest()->get(['id', 'kind', 'title', 'detail', 'evidence']) as $proposal) {
            $demand = self::demand((array) $proposal->evidence);
            $detail = (array) $proposal->detail;

            $fixes[] = match ($proposal->kind) {
                ImprovementProposal::SYNONYM => $this->synonym($shop, $proposal, $detail, $demand, $url),
                ImprovementProposal::FAQ => new TodayFix(
                    key: 'proposal:'.$proposal->id,
                    chip: TodayFix::ASKED,
                    count: $demand,
                    unit: 'times',
                    title: '“'.trim((string) ($detail['question'] ?? $proposal->title)).'”',
                    text: __('improvement::today.faq.text'),
                    action: __('improvement::today.faq.action'),
                    oneTap: false,
                    url: $url,
                    impact: $demand,
                ),
                default => new TodayFix(
                    key: 'proposal:'.$proposal->id,
                    chip: TodayFix::BIGGER,
                    count: $demand,
                    unit: 'searches',
                    title: trim((string) ($detail['topic'] ?? $proposal->title)),
                    text: trim((string) ($detail['why'] ?? '')) ?: __('improvement::today.gap.text'),
                    action: __('improvement::today.gap.action'),
                    oneTap: false,
                    url: $url,
                    // a page is more than a tap: it waits behind a quick fix of the same weight
                    impact: intdiv($demand, 2),
                ),
            };
        }

        return $fixes;
    }

    public function approve(Shop $shop, string $key, ?int $userId): ?string
    {
        if (! str_starts_with($key, 'proposal:')) {
            return null;
        }
        $proposal = ImprovementProposal::query()->whereKey((int) substr($key, 9))->where('kind', ImprovementProposal::SYNONYM)->first();
        if ($proposal === null) {
            return null;
        }
        $why = app(DecideProposal::class)->approve($proposal, $userId);

        return $why === null ? __('improvement::today.synonym.done', ['term' => (string) ($proposal->detail['term'] ?? '')]) : __($why);
    }

    /** The search counts what an approved synonym did (SearchFixesToday reads search_synonyms). */
    public function wins(Shop $shop, int $limit): array
    {
        return [];
    }

    /** @param array<string, mixed> $detail */
    private function synonym(Shop $shop, ImprovementProposal $proposal, array $detail, int $demand, string $url): TodayFix
    {
        $term = trim((string) ($detail['term'] ?? ''));
        $means = trim((string) ($detail['means'] ?? ''));
        $ids = [];
        try {
            $ids = app(ProductsForQuery::class)->find((string) $shop->id, $means, 50);
        } catch (Throwable $e) {
            report($e);
        }
        $pictures = CatalogProduct::query()->whereIn('external_id', array_slice($ids, 0, 2))->whereNotNull('image_url')->pluck('image_url')->map(fn ($u): string => (string) $u)->all();

        return new TodayFix(
            key: 'proposal:'.$proposal->id,
            chip: TodayFix::QUICK,
            count: $demand,
            unit: 'searches',
            title: __('improvement::today.synonym.title', ['term' => $term]),
            text: $ids === []
                ? __('improvement::today.synonym.text_plain', ['means' => $means])
                : trans_choice('improvement::today.synonym.text', count($ids), ['n' => count($ids), 'means' => $means]),
            action: __('improvement::today.synonym.action'),
            oneTap: true,
            url: $url,
            struck: $term,
            pictures: $pictures,
            impact: $demand,
        );
    }

    /** @param list<array<string, mixed>> $evidence the searches and questions behind a proposal */
    private static function demand(array $evidence): int
    {
        return (int) array_sum(array_map(fn ($e): int => is_array($e) ? (int) ($e['searches'] ?? $e['asked'] ?? $e['count'] ?? 0) : 0, $evidence));
    }
}
