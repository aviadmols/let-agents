<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Filament\HasPeriod;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * The shop manager's home, as the designer's panel lays it out (2026-10-18): the greeting with the period
 * (7, 30 or 90 days, or any dates); the five first steps until they are done; four cards (what shoppers
 * asked, the month's AI use, the products and the pages read); the period's numbers against the one before;
 * and what needs the manager. Every part comes from the modules (HomeCards, FirstSteps, HomeSource,
 * HomeNumbers); nothing here knows a module. No model, cost or mechanism.
 */
final class ShopOverview extends Page
{
    use HasPeriod;

    /** Hidden once every first step is done and the manager hid the closing line. */
    public const STEPS_HIDDEN = 'steps.hidden';

    /** The step "see it as a shopper", done when the manager opened the store from here. */
    public const LOOK_DONE = 'step.look';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'overview';

    protected string $view = 'admin::merchant.home';

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
        // A store's own account (one a platform signs in) is named after the store: no name to greet.
        $email = (string) Auth::user()?->email;
        $storeAccount = (str_starts_with($email, 'owner@') && str_ends_with($email, '.myshopify.com')) || (str_starts_with($email, 'store@') && str_ends_with($email, '.wordpress'));

        return $name === '' || $storeAccount
            ? __("admin::home.greeting.{$part}_plain")
            : __("admin::home.greeting.{$part}", ['name' => $name]);
    }

    /** The heading row as the design draws it: the greeting, and the period beside it. */
    public function getHeader(): ?View
    {
        return view('admin::merchant.home-head', ['title' => $this->getTitle(), 'subheading' => $this->subheading()]);
    }

    public function subheading(): string
    {
        [$since, $until] = $this->range();

        return __('admin::home.subheading', ['shop' => $this->shop()->name, 'period' => $this->periodLabel()]);
    }

    /** @return list<HomeCard> */
    public function cards(): array
    {
        [$since, $until] = $this->range();

        return app(Home::class)->cards($this->shop(), $since, $until);
    }

    /** @return list<FirstStep> */
    public function steps(): array
    {
        return app(Home::class)->steps($this->shop());
    }

    public function stepsHidden(): bool
    {
        return DB::table('shop_notices_done')->where('shop_id', $this->shop()->id)->where('key', self::STEPS_HIDDEN)->exists();
    }

    public function hideSteps(): void
    {
        app(Home::class)->markDone($this->shop(), self::STEPS_HIDDEN, auth()->id());
    }

    /** The manager opened the store as a shopper sees it, from the step. */
    public function looked(): void
    {
        app(Home::class)->markDone($this->shop(), self::LOOK_DONE, auth()->id());
    }

    /** @return list<ShopNotice> */
    public function attention(): array
    {
        return app(Home::class)->attention($this->shop());
    }

    /** @return array<string, mixed>|null */
    public function numbers(): ?array
    {
        [$since, $until] = $this->range();

        return app(Home::class)->numbers($this->shop(), $this->days ?: 30, $since, $until);
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
     * What modules put at the top of the overview (OverviewBlock): the store's scan, while it is new.
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

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
