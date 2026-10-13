<?php

namespace App\Modules\Ai\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\ChosenShop;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Filament\Operator\Pages\ShopBudgets;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;

/**
 * The bar at the top of every admin screen: how much of this month's model allowance is used.
 *
 * In a shop's panel, a share and the day it renews, never an amount: the shop manager does not
 * see what running the agents costs. In the operator panel, dollars, for the shop chosen in the
 * top bar or for every shop together, with the way to the screen where allowances are changed.
 */
final class UsageStrip
{
    public function __construct(private readonly SpendGuard $guard, private readonly ChosenShop $chosen) {}

    public function render(): HtmlString
    {
        $panel = Filament::getCurrentPanel()?->getId();

        if ($panel === User::MERCHANT_PANEL) {
            $shop = Filament::getTenant();

            return $shop instanceof Shop ? $this->forMerchant($shop) : new HtmlString('');
        }

        return $panel === User::OPERATOR_PANEL ? $this->forOperator() : new HtmlString('');
    }

    /** @return array{spent: float, cap: float, percent: int, tone: string} */
    public function usage(string $shopId): array
    {
        $spent = $this->guard->spentThisMonthFor($shopId);
        $cap = $this->guard->capFor($shopId);

        return ['spent' => $spent, 'cap' => $cap, ...self::share($spent, $cap)];
    }

    /** @return array{percent: int, tone: string} */
    public static function share(float $spent, float $cap): array
    {
        $percent = $cap > 0 ? (int) floor(100 * $spent / $cap) : ($spent > 0 ? 100 : 0);
        $tone = $percent >= 100 ? 'bad' : ($percent >= (int) Settings::get('ai.shop_cap_warn_percent') ? 'warn' : '');

        return ['percent' => max(0, $percent), 'tone' => $tone];
    }

    private function forMerchant(Shop $shop): HtmlString
    {
        $usage = $this->usage((string) $shop->id);

        return new HtmlString(view('ai::usage-strip', [
            'title' => __('ai::budget.strip.title'),
            'percent' => $usage['percent'],
            'tone' => $usage['tone'],
            'main' => __('ai::budget.strip.share', ['percent' => min($usage['percent'], 100)]),
            'side' => __('ai::budget.strip.renews', ['date' => now()->utc()->startOfMonth()->addMonth()->locale(app()->getLocale())->translatedFormat(__('ai::budget.strip.date_format'))]),
            'note' => match ($usage['tone']) {
                'bad' => __(Settings::get('ai.shop_cap_stops_shoppers', (string) $shop->id) ? 'ai::budget.strip.used_up_all' : 'ai::budget.strip.used_up'),
                'warn' => __('ai::budget.strip.near'),
                default => __('ai::budget.strip.set_by'),
            },
            'link' => null,
            'linkLabel' => null,
        ])->render());
    }

    private function forOperator(): HtmlString
    {
        $chosen = $this->chosen->get();

        if ($chosen !== null) {
            $usage = $this->usage($chosen['id']);
            $title = __('ai::budget.operator_strip.shop', ['shop' => $chosen['name']]);
            $main = __('ai::budget.operator_strip.spent', ['spent' => number_format($usage['spent'], 2), 'cap' => number_format($usage['cap'], 2), 'percent' => $usage['percent']]);
        } else {
            $spent = $this->guard->spentThisMonth();
            $caps = (float) Shop::query()->pluck('id')->sum(fn (string $id): float => $this->guard->capFor($id));
            $usage = self::share($spent, $caps);
            $title = __('ai::budget.operator_strip.every');
            $main = __('ai::budget.operator_strip.every_spent', ['spent' => number_format($spent, 2), 'caps' => number_format($caps, 2), 'global' => number_format($this->guard->cap(), 2)]);
        }

        return new HtmlString(view('ai::usage-strip', [
            'title' => $title,
            'percent' => $usage['percent'],
            'tone' => $usage['tone'],
            'main' => $main,
            'side' => null,
            'note' => null,
            'link' => ShopBudgets::getUrl(panel: User::OPERATOR_PANEL),
            'linkLabel' => __('ai::budget.operator_strip.edit'),
        ])->render());
    }
}
