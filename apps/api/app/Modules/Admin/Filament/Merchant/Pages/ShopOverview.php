<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop manager's home, as in the sketch: the store's scan first when it is new; what waits
 * today; what is on and what is not; the period's numbers against the one before; what needs an
 * answer; what each module brought; and the latest notices. Every part comes from the modules
 * (Admin\Contracts\HomeSource); nothing here knows a module. No model, cost or mechanism.
 */
final class ShopOverview extends Page
{
    public const PERIODS = [7, 30, 90];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'overview';

    protected string $view = 'admin::merchant.home';

    #[Url]
    public int $days = 30;

    public static function getNavigationLabel(): string
    {
        return __('admin::home.nav');
    }

    public function getTitle(): string
    {
        $hour = (int) now()->timezone('Asia/Jerusalem')->format('G');
        $part = $hour < 5 ? 'night' : ($hour < 12 ? 'morning' : ($hour < 17 ? 'noon' : ($hour < 21 ? 'evening' : 'night')));
        // The first name, past a title ("Mr.", "ד״ר").
        $name = (string) collect(explode(' ', trim((string) Auth::user()?->name)))->first(fn (string $part): bool => $part !== '' && ! str_ends_with($part, '.') && ! in_array($part, ['ד״ר', 'דר', 'פרופ׳'], true));
        // The store's own account (owner@{store}.myshopify.com) is named after the store: no name to greet.
        $storeAccount = str_starts_with((string) Auth::user()?->email, 'owner@') && str_ends_with((string) Auth::user()?->email, '.myshopify.com');

        return $name === '' || $storeAccount
            ? __("admin::home.greeting.{$part}_plain")
            : __("admin::home.greeting.{$part}", ['name' => $name]);
    }

    public function getSubheading(): ?string
    {
        return __('admin::home.subheading', [
            'date' => now()->timezone('Asia/Jerusalem')->locale(app()->getLocale())->translatedFormat(__('admin::home.date_format')),
            'shop' => $this->shop()->name,
        ]);
    }

    public function setDays(int $days): void
    {
        $this->days = in_array($days, self::PERIODS, true) ? $days : 30;
    }

    /** Hidden until tomorrow, on this browser's session: the cards come back the next day. */
    public function hideToday(): void
    {
        session()->put($this->hiddenKey(), true);
    }

    public function showToday(): void
    {
        session()->forget($this->hiddenKey());
    }

    public function todayHidden(): bool
    {
        return (bool) session()->get($this->hiddenKey(), false);
    }

    /** @return list<ShopNotice> */
    public function today(): array
    {
        return app(Home::class)->today($this->shop());
    }

    /** @return list<HomeStatus> */
    public function statuses(): array
    {
        return app(Home::class)->statuses($this->shop());
    }

    /** @return list<ShopNotice> */
    public function notices(): array
    {
        return app(Home::class)->notices($this->shop());
    }

    /** @return list<ModuleFigure> */
    public function figures(): array
    {
        return app(Home::class)->figures($this->shop(), $this->period());
    }

    /** @return array<string, mixed>|null */
    public function numbers(): ?array
    {
        return app(Home::class)->numbers($this->shop(), $this->period());
    }

    /** The change from the period before, as the pill under a number shows it. */
    public static function delta(int|float $now, int|float $before): array
    {
        if ($before == 0) {
            return $now > 0 ? ['text' => __('admin::home.new'), 'tone' => 'good'] : ['text' => '—', 'tone' => ''];
        }

        $change = (int) round(100 * ($now - $before) / $before);

        return [
            'text' => ($change > 0 ? '▲ ' : ($change < 0 ? '▼ ' : '● ')).abs($change).'%',
            'tone' => $change > 0 ? 'good' : ($change < 0 ? 'bad' : ''),
        ];
    }

    public static function money(float $amount, string $currency): string
    {
        $symbol = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency.' ';

        return $symbol.number_format($amount, $amount >= 1000 ? 0 : 2);
    }

    public function inboxUrl(): string
    {
        return ShopNotices::getUrl();
    }

    /**
     * What modules put at the top of the overview (OverviewBlock): the store's scan, first of all.
     *
     * @return list<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return array_values(array_filter(
            Filament::getCurrentPanel()?->getWidgets() ?? [],
            fn (string $widget): bool => is_subclass_of($widget, OverviewBlock::class),
        ));
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    private function period(): int
    {
        return in_array($this->days, self::PERIODS, true) ? $this->days : 30;
    }

    private function hiddenKey(): string
    {
        return 'home.today_hidden.'.$this->shop()->id.'.'.now()->toDateString();
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
