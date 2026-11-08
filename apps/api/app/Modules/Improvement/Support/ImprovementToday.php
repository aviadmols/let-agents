<?php

namespace App\Modules\Improvement\Support;

use App\Core\Localization\Money;
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
 * The review's proposals on Today's "Waiting for you" and the Improvements screen: a word that found nothing and the
 * word it means, approved in one tap, with the products shoppers will see then ("Quick win"); a question shoppers ask,
 * with its drafted answer to read, edit and publish in one tap ("Asked often"); a page or post the store lacks ("Bigger
 * idea"). Each with what exactly shoppers typed or asked. Ranked by the searches and questions behind each. "Not
 * relevant" rejects a proposal, as the suggestions screen does. What they did once approved is the search's to count
 * (SearchFixesToday). No model here.
 */
final class ImprovementToday implements TodayImprovements
{
    /** Products the suggestion shows, at most; the rest is "+N more". */
    private const PREVIEW = 3;

    public function waiting(Shop $shop): array
    {
        $url = SiteSuggestions::getUrl(panel: 'merchant', tenant: $shop);
        $fixes = [];

        foreach (ImprovementProposal::query()->where('status', ImprovementProposal::PENDING)->latest()->get(['id', 'kind', 'title', 'detail', 'evidence']) as $proposal) {
            $evidence = (array) $proposal->evidence;
            $demand = self::demand($evidence);
            $detail = (array) $proposal->detail;
            $typed = self::typed($evidence);

            $fixes[] = match ($proposal->kind) {
                ImprovementProposal::SYNONYM => $this->synonym($shop, $proposal, $detail, $demand, $typed, $url),
                ImprovementProposal::FAQ => new TodayFix(
                    key: 'proposal:'.$proposal->id,
                    chip: TodayFix::ASKED,
                    count: $demand,
                    unit: 'times',
                    title: '“'.trim((string) ($detail['question'] ?? $proposal->title)).'”',
                    text: __('improvement::today.faq.text'),
                    action: trim((string) ($detail['answer'] ?? '')) !== '' ? __('improvement::today.faq.publish') : __('improvement::today.faq.action'),
                    // a drafted answer is published here, as the owner leaves it; none yet: the suggestions screen writes one
                    oneTap: trim((string) ($detail['answer'] ?? '')) !== '',
                    url: $url,
                    impact: $demand,
                    kind: 'question',
                    suggestion: trim((string) ($detail['answer'] ?? '')) === '' ? [] : ['type' => 'answer', 'answer' => trim((string) $detail['answer'])],
                    typed: $typed,
                    secondary: ['label' => __('improvement::today.faq.open'), 'url' => $url],
                    editable: true,
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
                    kind: 'idea',
                    suggestion: ['type' => 'idea'],
                    typed: $typed,
                ),
            };
        }

        return $fixes;
    }

    public function approve(Shop $shop, string $key, ?int $userId, ?string $text = null): ?string
    {
        $proposal = $this->proposal($key);
        if ($proposal === null || ! in_array($proposal->kind, [ImprovementProposal::SYNONYM, ImprovementProposal::FAQ], true)) {
            return null;
        }
        $why = app(DecideProposal::class)->approve($proposal, $userId, $proposal->kind === ImprovementProposal::FAQ ? $text : null);
        if ($why !== null) {
            return __($why);
        }

        return $proposal->kind === ImprovementProposal::FAQ
            ? __('improvement::today.faq.done')
            : __('improvement::today.synonym.done', ['term' => (string) ($proposal->detail['term'] ?? '')]);
    }

    public function dismiss(Shop $shop, string $key, ?int $userId): bool
    {
        $proposal = $this->proposal($key);
        if ($proposal === null) {
            return false;
        }
        app(DecideProposal::class)->reject($proposal, $userId);

        return true;
    }

    /** The search counts what an approved synonym did (SearchFixesToday reads search_synonyms). */
    public function wins(Shop $shop, int $limit): array
    {
        return [];
    }

    private function proposal(string $key): ?ImprovementProposal
    {
        return str_starts_with($key, 'proposal:')
            ? ImprovementProposal::query()->whereKey(substr($key, 9))->where('status', ImprovementProposal::PENDING)->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  list<array{text: string, n: int}>  $typed
     */
    private function synonym(Shop $shop, ImprovementProposal $proposal, array $detail, int $demand, array $typed, string $url): TodayFix
    {
        $term = trim((string) ($detail['term'] ?? ''));
        $means = trim((string) ($detail['means'] ?? ''));
        $ids = [];
        try {
            $ids = app(ProductsForQuery::class)->find((string) $shop->id, $means, 50);
        } catch (Throwable $e) {
            report($e);
        }
        $products = CatalogProduct::query()->whereIn('external_id', array_slice($ids, 0, self::PREVIEW))->get(['external_id', 'title', 'image_url', 'price'])->keyBy('external_id');
        $currency = (string) ($shop->currency ?: 'ILS');
        $preview = array_values(array_filter(array_map(fn (string $id): ?array => isset($products[$id]) ? [
            'title' => (string) $products[$id]->title,
            'picture' => $products[$id]->image_url,
            'price' => $products[$id]->price === null ? null : Money::total((float) $products[$id]->price, $currency),
        ] : null, array_slice($ids, 0, self::PREVIEW))));

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
            pictures: array_values(array_filter(array_column($preview, 'picture'))),
            impact: $demand,
            kind: 'nothing',
            suggestion: ['type' => 'rule', 'from' => $term, 'to' => $means, 'products' => $preview, 'more' => max(0, count($ids) - count($preview))],
            typed: $typed,
            secondary: ['label' => __('improvement::today.synonym.other'), 'url' => $url],
        );
    }

    /** @param list<array<string, mixed>> $evidence the searches and questions behind a proposal */
    private static function demand(array $evidence): int
    {
        return (int) array_sum(array_map(fn ($e): int => is_array($e) ? (int) ($e['searches'] ?? $e['asked'] ?? $e['count'] ?? 0) : 0, $evidence));
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return list<array{text: string, n: int}> what exactly shoppers typed or asked, the most first
     */
    private static function typed(array $evidence): array
    {
        $out = [];
        foreach ($evidence as $e) {
            $text = is_array($e) ? trim((string) ($e['query'] ?? $e['question'] ?? '')) : '';
            if ($text !== '') {
                $out[] = ['text' => $text, 'n' => (int) ($e['searches'] ?? $e['asked'] ?? $e['count'] ?? 0)];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        return array_slice($out, 0, 6);
    }
}
