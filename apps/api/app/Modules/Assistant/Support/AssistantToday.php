<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;

/**
 * The questions shoppers asked that the assistant could not answer, on Today's "Waiting for you": the ones asked in
 * the last month, each a card that opens the questions screen to answer it. Asked again and again is "Asked often";
 * once or twice is "Nice to have". The answer is the owner's to write, so none is one tap. No model.
 */
final class AssistantToday implements TodayImprovements
{
    private const DAYS = 30;

    /** At most this many questions come to Today; the rest wait on the questions screen. */
    private const SHOWN = 5;

    public function waiting(Shop $shop): array
    {
        $url = ShopQuestions::getUrl(panel: 'merchant', tenant: $shop);
        $open = AssistantAnswer::query()
            ->where('status', AssistantAnswer::SHOWN)
            ->where('outcome', AssistantAnswer::NO_INFO)
            ->where('last_asked_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('asked_count')->orderByDesc('last_asked_at')
            ->limit(self::SHOWN)->get(['id', 'question', 'asked_count', 'product_id']);
        $pictures = CatalogProduct::query()->whereIn('id', $open->pluck('product_id')->filter()->unique()->values())->whereNotNull('image_url')->pluck('image_url', 'id');

        return $open->map(fn (AssistantAnswer $answer): TodayFix => new TodayFix(
            key: 'question:'.$answer->id,
            chip: (int) $answer->asked_count >= 3 ? TodayFix::ASKED : TodayFix::NICE,
            count: max(1, (int) $answer->asked_count),
            unit: 'times',
            title: '“'.trim((string) $answer->question).'”',
            text: __('assistant::today.open.text'),
            action: __('assistant::today.open.action'),
            oneTap: false,
            url: $url,
            pictures: $answer->product_id !== null && isset($pictures[$answer->product_id]) ? [(string) $pictures[$answer->product_id]] : [],
            impact: (int) $answer->asked_count,
        ))->all();
    }

    public function approve(Shop $shop, string $key, ?int $userId): ?string
    {
        return null;
    }

    public function wins(Shop $shop, int $limit): array
    {
        return [];
    }
}
