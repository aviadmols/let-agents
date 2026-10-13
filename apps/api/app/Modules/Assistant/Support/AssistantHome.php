<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The assistant on the shop's home: is it answering, the questions it could not answer that wait
 * for the team, and how many questions it got. Asked inside the shop.
 */
final class AssistantHome implements HomeSource
{
    private const OPEN_DAYS = 30;

    public function statuses(Shop $shop): array
    {
        $on = Features::enabled('assistant.on_search', $shop->id) || Features::enabled('assistant.on_products', $shop->id);
        $week = AssistantSearchAsk::query()->where('created_at', '>=', now()->subDays(7))->count();

        return [new HomeStatus(
            key: 'assistant',
            name: __('assistant::home.name'),
            state: $on ? HomeStatus::ON : HomeStatus::OFF,
            note: $on ? __('assistant::home.on', ['count' => number_format($week)]) : __('assistant::home.off'),
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            action: __('assistant::home.open'),
            order: 20,
        )];
    }

    public function notices(Shop $shop): array
    {
        $open = AssistantAnswer::query()
            ->where('status', AssistantAnswer::SHOWN)
            ->where('outcome', AssistantAnswer::NO_INFO)
            ->where('last_asked_at', '>=', now()->subDays(self::OPEN_DAYS))
            ->orderByDesc('asked_count')->orderByDesc('last_asked_at')
            ->get(['id', 'question', 'asked_count', 'last_asked_at']);

        if ($open->isEmpty()) {
            return [];
        }

        $top = $open->first();

        return [new ShopNotice(
            key: 'assistant.open',
            kind: ShopNotice::TODO,
            tone: 'warn',
            title: trans_choice('assistant::home.open_title', $open->count(), ['count' => $open->count()]),
            body: __('assistant::home.open_body', ['question' => $top->question, 'times' => (int) $top->asked_count]),
            source: __('assistant::home.questions'),
            at: $top->last_asked_at,
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            action: __('assistant::home.answer'),
            weight: 90,
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $asks = fn (CarbonInterface $from, CarbonInterface $to): int => AssistantSearchAsk::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        return [new ModuleFigure(
            key: 'assistant',
            name: __('assistant::home.name'),
            uses: $asks($since, $until),
            previousUses: $asks($before, $since),
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            order: 20,
        )];
    }
}
