<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Every store's answers the daily report scored low, or marked as inventing, refusing with the
 * answer at hand, or picking off the question: one list for the operator, the worst first, each
 * with the store, the question, what was shown and why it is here.
 */
class WeakAnswers extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'assistant/weak-answers';

    protected string $view = 'assistant::operator.weak-answers';

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.weak.title');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = count(self::weak());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('assistant::ui.weak.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.weak.subheading', ['days' => (int) Settings::get('assistant.weak_answer_days'), 'score' => (int) Settings::get('assistant.weak_answer_max_score')]);
    }

    /**
     * @return list<array{shop_id: string, shop: string, day: string, score: int, flag: string|null, note: string, question: string, answer: string, outcome: string, picks: list<string>}>
     */
    public function items(): array
    {
        $weak = self::weak();

        return app(TenantContext::class)->runUnscoped(function () use ($weak): array {
            $asks = AssistantSearchAsk::query()->whereIn('id', array_column($weak, 'ask_id'))->get()->keyBy('id');
            $answers = AssistantAnswer::query()->whereIn('id', $asks->pluck('answer_id')->filter()->unique()->values())->pluck('answer', 'id');
            $shops = Shop::query()->whereIn('id', array_values(array_unique(array_column($weak, 'shop_id'))))->pluck('name', 'id');
            $out = [];

            foreach ($weak as $item) {
                $ask = $asks->get($item['ask_id']);
                if ($ask === null) {
                    continue;
                }
                $out[] = [
                    'shop_id' => $item['shop_id'],
                    'shop' => (string) ($shops[$item['shop_id']] ?? ''),
                    'day' => $item['day'],
                    'score' => (int) $item['score'],
                    'flag' => $item['flag'] ?? null,
                    'note' => (string) ($item['note'] ?? ''),
                    'question' => (string) $ask->question,
                    'answer' => (string) ($answers[$ask->answer_id] ?? $ask->answer ?? ''),
                    'outcome' => (string) $ask->outcome,
                    'picks' => array_map(fn (array $p): string => (string) $p['title'], (array) $ask->picks),
                ];
            }

            return $out;
        });
    }

    /**
     * The reviewed items of the last days at or under the score, or flagged: flagged first, then the
     * lowest score, then the newest day.
     *
     * @return list<array{ask_id: string, score: int, note?: string, flag?: string, shop_id: string, day: string}>
     */
    private static function weak(): array
    {
        $maxScore = (int) Settings::get('assistant.weak_answer_max_score');
        $since = now()->subDays((int) Settings::get('assistant.weak_answer_days'))->toDateString();

        return app(TenantContext::class)->runUnscoped(function () use ($maxScore, $since): array {
            $weak = [];
            $reviews = AssistantAskReview::query()->where('status', AssistantAskReview::REVIEWED)->whereDate('day', '>=', $since)->get(['shop_id', 'day', 'items']);

            foreach ($reviews as $review) {
                foreach ((array) $review->items as $item) {
                    if (is_array($item) && isset($item['ask_id']) && ((int) ($item['score'] ?? 5) <= $maxScore || isset($item['flag']))) {
                        $weak[] = $item + ['shop_id' => (string) $review->shop_id, 'day' => $review->day->toDateString()];
                    }
                }
            }

            usort($weak, fn (array $a, array $b): int => [isset($a['flag']) ? 0 : 1, (int) $a['score'], $b['day']] <=> [isset($b['flag']) ? 0 : 1, (int) $b['score'], $a['day']]);

            return $weak;
        });
    }
}
