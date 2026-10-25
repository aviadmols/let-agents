<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The assistant on the shop's home: is it answering, the questions it could not answer that wait
 * for the team, how many questions it got (the "questions" card), and the first step "answer the
 * first question", done once the team answered one. Asked inside the shop.
 */
final class AssistantHome implements FirstSteps, HomeCards, HomeSource
{
    private const OPEN_DAYS = 30;

    public function statuses(Shop $shop): array
    {
        $on = Features::enabled('assistant.on_search', $shop->id) || Features::enabled('assistant.on_products', $shop->id);
        // the questions of the last 7 days, counted as the card and the analytics screen count them
        $week = QuestionCounts::between(now()->subDays(7), now())['all'];

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
        $open = $this->open();

        if ($open->isEmpty()) {
            return [];
        }

        $top = $open->first();

        return [new ShopNotice(
            key: 'assistant.open',
            kind: ShopNotice::TODO,
            tone: 'bad',
            title: trans_choice('assistant::home.open_title', $open->count(), ['count' => $open->count()]),
            body: __('assistant::home.open_body', ['question' => $top->question, 'times' => (int) $top->asked_count]),
            source: __('assistant::home.questions'),
            at: $top->last_asked_at,
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            action: __('assistant::home.answer'),
            weight: 90,
            count: $open->count(),
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $asks = fn (CarbonInterface $from, CarbonInterface $to): int => QuestionCounts::between($from, $to)['all'];

        return [new ModuleFigure(
            key: 'assistant',
            name: __('assistant::home.name'),
            uses: $asks($since, $until),
            previousUses: $asks($before, $since),
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            order: 20,
        )];
    }

    /**
     * The questions shoppers asked in the period, as the analytics screen counts them (QuestionCounts),
     * with how many were answered from what the store knows.
     */
    public function cards(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $now = QuestionCounts::between($since, $until);
        $earlier = QuestionCounts::between($before, $since);

        return [new HomeCard(
            key: 'questions',
            order: 10,
            label: __('assistant::home.card.label'),
            value: number_format($now['all']),
            note: __('assistant::home.card.answered', ['count' => number_format($now['answered'])]),
            change: [$now['all'], $earlier['all']],
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
        )];
    }

    public function steps(Shop $shop): array
    {
        $top = $this->open()->first();

        return [new FirstStep(
            key: 'answer',
            order: 30,
            title: __('assistant::home.step.title'),
            body: $top === null ? __('assistant::home.step.body_none') : __('assistant::home.step.body', ['question' => $top->question, 'times' => (int) $top->asked_count]),
            button: __('assistant::home.step.button'),
            url: ShopQuestions::getUrl(panel: 'merchant', tenant: $shop),
            done: AssistantAnswer::query()->where('source', 'team')->exists(),
        )];
    }

    private function open()
    {
        return AssistantAnswer::query()
            ->where('status', AssistantAnswer::SHOWN)
            ->where('outcome', AssistantAnswer::NO_INFO)
            ->where('last_asked_at', '>=', now()->subDays(self::OPEN_DAYS))
            ->orderByDesc('asked_count')->orderByDesc('last_asked_at')
            ->get(['id', 'question', 'asked_count', 'last_asked_at']);
    }
}
