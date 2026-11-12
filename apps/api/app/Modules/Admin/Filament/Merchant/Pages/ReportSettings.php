<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Mail\StoreReportMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\StoreReport;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

/**
 * Settings · Store report (Tomer's design, 12.11): how often the owners get the store report by email, daily, weekly
 * (the default), monthly or off, and when the next one goes out; who gets it (the owners); one sent now to whoever asks,
 * and an example of it. Only an owner changes how often.
 */
class ReportSettings extends Page
{
    public static string $sideGroup = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 112;

    protected static ?string $slug = 'settings/report';

    protected string $view = 'admin::merchant.settings.report';

    public static function getNavigationLabel(): string
    {
        return __('admin::report.nav');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    public function shop(): Shop
    {
        $shop = Filament::getTenant();
        abort_unless($shop instanceof Shop, 404);

        return $shop;
    }

    public function owner(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->managesShop($this->shop());
    }

    public function frequency(): string
    {
        return StoreReport::frequency($this->shop());
    }

    /** How often, as an owner chose it: saved at once. */
    public function choose(string $frequency): void
    {
        abort_unless($this->owner(), 403);
        if (! in_array($frequency, StoreReport::FREQUENCIES, true)) {
            return;
        }

        Settings::set('admin.report_frequency', $frequency, (string) $this->shop()->id);
        Notification::make()->success()->title(__('admin::report.settings.saved'))->send();
    }

    /** When the next report goes out, on the store's clock, or null when it is off. */
    public function next(): ?CarbonImmutable
    {
        $frequency = $this->frequency();
        if ($frequency === StoreReport::OFF) {
            return null;
        }
        $at = CarbonImmutable::now((string) ($this->shop()->timezone ?: 'Asia/Jerusalem'))->addHour()->startOfHour();
        for ($i = 0; $i < 24 * 32; $i++, $at = $at->addHour()) {
            if (StoreReport::due($frequency, $at)) {
                return $at;
            }
        }

        return null;
    }

    /** @return list<string> who gets it: the store's owners */
    public function recipients(): array
    {
        return self::ownerEmails($this->shop());
    }

    /** @return list<string> */
    public static function ownerEmails(Shop $shop): array
    {
        return $shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)
            ->pluck('email')->filter()->map(fn ($e): string => (string) $e)->unique()->values()->all();
    }

    /** One report now, to whoever asks, of the period their store's report covers (a week when it is off). */
    public function sendNow(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->email, 403);
        $frequency = $this->frequency() === StoreReport::OFF ? StoreReport::WEEKLY : $this->frequency();
        $report = app(StoreReport::class)->of($this->shop(), $frequency);

        if ($report === null) {
            Notification::make()->warning()->title(__('admin::report.settings.nothing'))->send();

            return;
        }

        Mail::to((string) $user->email)->locale($report['locale'])->send(new StoreReportMail($report));
        Notification::make()->success()->title(__('admin::report.settings.sent', ['email' => $user->email]))->send();
    }

    public function exampleUrl(): string
    {
        return WeeklyEmailPreview::getUrl(panel: 'merchant', tenant: $this->shop());
    }
}
