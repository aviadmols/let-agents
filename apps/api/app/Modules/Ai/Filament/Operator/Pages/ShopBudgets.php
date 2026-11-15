<?php

namespace App\Modules\Ai\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Support\MonthlySpendGuard;
use App\Modules\Ai\Support\UsageStrip;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Every shop's model allowance for the month: what it spent, against what, on what. The only place
 * an allowance changes; the shop manager sees just the share used, at the top of their screens.
 */
final class ShopBudgets extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'budgets';

    protected string $view = 'ai::operator.budgets';

    /** The shop whose allowance is open for editing. */
    public ?string $editing = null;

    public string $cap = '';

    public bool $stops = false;

    public static function getNavigationLabel(): string
    {
        return __('ai::budget.screen.title');
    }

    public function getTitle(): string
    {
        return __('ai::budget.screen.title');
    }

    public function getSubheading(): ?string
    {
        return __('ai::budget.screen.subheading', ['default' => self::money(self::defaultCap())]);
    }

    /**
     * Every shop with this month's spend, its allowance and where the money went, the fullest first.
     *
     * @return list<array{id: string, name: string, platform: string, spent: float, cap: float, own: bool, calls: int, percent: int, tone: string, state: string, top: ?string, stops: bool}>
     */
    public function rows(): array
    {
        $spent = MonthlySpendGuard::byShop();
        $top = $this->costliestAgents();
        $guard = app(SpendGuard::class);

        return Shop::query()->orderBy('name')->get()
            ->map(function (Shop $shop) use ($spent, $top, $guard): array {
                $id = (string) $shop->id;
                $used = $spent[$id] ?? ['spent' => 0.0, 'calls' => 0];
                $cap = $guard->capFor($id);
                $share = UsageStrip::share($used['spent'], $cap);

                return [
                    'id' => $id,
                    'name' => (string) $shop->name,
                    'platform' => $shop->platform->label(),
                    'spent' => $used['spent'],
                    'cap' => $cap,
                    'own' => Settings::overrideFor('ai.shop_monthly_cap_usd', $id) !== null,
                    'calls' => $used['calls'],
                    'percent' => $share['percent'],
                    'tone' => $share['tone'],
                    'state' => match (true) {
                        $used['calls'] === 0 => 'idle',
                        $share['tone'] === 'bad' => 'over',
                        $share['tone'] === 'warn' => 'near',
                        default => 'ok',
                    },
                    'top' => $top[$id] ?? null,
                    'stops' => (bool) Settings::get('ai.shop_cap_stops_shoppers', $id),
                ];
            })
            ->sortByDesc('percent')
            ->values()
            ->all();
    }

    public function edit(string $shopId): void
    {
        $this->editing = $shopId;
        $this->cap = (string) app(SpendGuard::class)->capFor($shopId);
        $this->stops = (bool) Settings::get('ai.shop_cap_stops_shoppers', $shopId);
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $shop = $this->editing === null ? null : Shop::query()->find($this->editing);

        if ($shop === null) {
            $this->editing = null;

            return;
        }

        try {
            $cap = Settings::set('ai.shop_monthly_cap_usd', $this->cap, (string) $shop->id);
            Settings::set('ai.shop_cap_stops_shoppers', $this->stops, (string) $shop->id);
        } catch (InvalidSettingValue $invalid) {
            $this->addError('cap', $invalid->translated());

            return;
        }

        $this->editing = null;
        Notification::make()->success()->title(__('ai::budget.screen.form.saved', ['shop' => $shop->name, 'cap' => self::money((float) $cap)]))->send();
    }

    /** The allowance goes back to the platform's default, and with it the shopper switch. */
    public function useDefault(): void
    {
        $shop = $this->editing === null ? null : Shop::query()->find($this->editing);

        if ($shop !== null) {
            Settings::clear('ai.shop_monthly_cap_usd', (string) $shop->id);
            Settings::clear('ai.shop_cap_stops_shoppers', (string) $shop->id);
            Notification::make()->success()->title(__('ai::budget.screen.form.cleared', ['shop' => $shop->name]))->send();
        }

        $this->editing = null;
    }

    public static function defaultCap(): float
    {
        return (float) Settings::get('ai.shop_monthly_cap_usd');
    }

    public static function money(float $usd): string
    {
        return number_format($usd, $usd >= 100 || floor($usd) === $usd ? 0 : 2);
    }

    /**
     * The agent that cost each shop the most this month, with what it cost.
     *
     * @return array<string, string> shop id => "Picture reader · $14.10"
     */
    private function costliestAgents(): array
    {
        $rows = Run::query()
            ->where('created_at', '>=', now()->utc()->startOfMonth())
            ->whereNotNull('cost_usd')
            ->whereNotNull('shop_id')
            ->select('shop_id', 'agent', DB::raw('sum(cost_usd) as cost'))
            ->groupBy('shop_id', 'agent')
            ->get()
            ->groupBy('shop_id');

        return $rows->map(function ($agents): string {
            $top = $agents->sortByDesc(fn ($row): float => (float) $row->cost)->first();

            return Run::labelFor('agents', (string) $top->agent).' · $'.number_format((float) $top->cost, 2);
        })->mapWithKeys(fn (string $label, $shopId): array => [(string) $shopId => $label])->all();
    }
}
