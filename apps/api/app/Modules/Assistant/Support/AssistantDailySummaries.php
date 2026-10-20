<?php

namespace App\Modules\Assistant\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\DailySummaries;
use App\Modules\Admin\Contracts\DailySummary;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions as MerchantQuestions;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions as OperatorQuestions;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The daily report of the questions shoppers asked in the search box, a card a day: its score, what
 * the review said, and which questions' answers entered the store's bank of answers and which were
 * left out (ReviewSearchAsks::bank), with why.
 */
final class AssistantDailySummaries implements DailySummaries
{
    public const KIND = 'questions';

    /** Questions shown in each list of a card. */
    private const ITEMS = 8;

    /** Reports read at most, across shops. */
    private const MAX = 300;

    public function __construct(private readonly TenantContext $tenant) {}

    public function label(): string
    {
        return __('assistant::ui.daily.label');
    }

    public function summaries(?string $shopId, CarbonInterface $since, bool $operator = false): array
    {
        $read = function () use ($shopId, $since, $operator): array {
            $reviews = AssistantAskReview::query()->where('status', AssistantAskReview::REVIEWED)->where('day', '>=', $since->toDateString())
                ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId))->orderByDesc('day')->limit(self::MAX)->get();
            $askIds = $reviews->flatMap(fn (AssistantAskReview $r): array => array_column(array_filter((array) $r->items, fn (array $i): bool => isset($i['bank'])), 'ask_id'))->unique()->values()->all();
            $questions = AssistantSearchAsk::query()->whereIn('id', $askIds)->pluck('question', 'id');
            $shops = Shop::query()->whereIn('id', $reviews->pluck('shop_id')->unique())->get()->keyBy('id');

            return $reviews->map(function (AssistantAskReview $review) use ($questions, $shops, $operator): DailySummary {
                $in = [];
                $out = [];
                foreach ((array) $review->items as $item) {
                    $question = trim((string) ($questions[$item['ask_id']] ?? ''));
                    if ($question === '' || ! isset($item['bank'])) {
                        continue;
                    }
                    if ($item['bank'] === 'in') {
                        $in[] = $question;
                    } else {
                        $out[] = $question.(trim((string) ($item['note'] ?? '')) === '' ? '' : ' — '.trim((string) $item['note']));
                    }
                }
                $shop = $shops->get($review->shop_id);

                return new DailySummary(
                    kind: self::KIND,
                    shopId: (string) $review->shop_id,
                    day: $review->day,
                    headline: __('assistant::ui.daily.headline', ['score' => (string) ($review->score ?? '—'), 'asks' => number_format((int) ($review->counts['asks'] ?? 0))]),
                    summary: $review->summary,
                    lists: array_values(array_filter([
                        $in === [] ? null : ['label' => __('assistant::ui.daily.in', ['count' => count($in)]), 'items' => array_slice($in, 0, self::ITEMS), 'tone' => 'good'],
                        $out === [] ? null : ['label' => __('assistant::ui.daily.out', ['count' => count($out)]), 'items' => array_slice($out, 0, self::ITEMS), 'tone' => 'warn'],
                    ])),
                    url: $shop === null ? null : ($operator ? OperatorQuestions::getUrl(['shop' => $shop->id], panel: 'operator') : MerchantQuestions::getUrl(panel: 'merchant', tenant: $shop)),
                    tone: $review->score === null ? '' : ($review->score >= 80 ? 'good' : ($review->score >= 60 ? 'warn' : 'bad')),
                );
            })->values()->all();
        };

        return $shopId === null ? $this->tenant->runUnscoped($read) : $this->tenant->run($shopId, $read);
    }
}
