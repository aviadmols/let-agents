<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Modules\Admin\Actions\AskToDeleteShop;
use App\Modules\Admin\Actions\DeleteOwnAccount;
use App\Modules\Admin\Actions\EndShopPlan;
use App\Modules\Admin\Actions\KeepShop;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnitEnum;

/**
 * Settings · Privacy and account: what the shop's owner can take and end. Downloading everything the shop holds
 * (ExportShopData); ending the plan at once (EndShopPlan: the search leaves the site, nothing is lost); asking for
 * the shop and all it holds to be deleted, with days to change one's mind (AskToDeleteShop, KeepShop). And for
 * anyone, viewers too: deleting their own account (DeleteOwnAccount), the shops staying, once they type PHRASE as
 * it is shown (not a password: one who came in from Shopify may never have set one).
 */
class PrivacySettings extends Page
{
    /** What deleting one's own account asks to be typed, exactly. */
    public const PHRASE = 'DELETE LETS';

    public static string $sideGroup = 'account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 140;

    protected static ?string $slug = 'settings/privacy';

    protected string $view = 'admin::merchant.settings.privacy';

    /** Which confirmation is open: 'plan', 'shop' or 'me'. */
    public ?string $confirming = null;

    public string $shopName = '';

    public string $phrase = '';

    /** @var array<string, string> */
    public array $problems = [];

    public static function getNavigationLabel(): string
    {
        return __('admin::privacy.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    public function problem(string $field): ?string
    {
        return $this->problems[$field] ?? null;
    }

    public function owner(): bool
    {
        return $this->user()->managesShop($this->shop());
    }

    public function shop(): Shop
    {
        $shop = Filament::getTenant();
        abort_unless($shop instanceof Shop, 404);

        return $shop->refresh();
    }

    public function exportUrl(): string
    {
        return route('admin.privacy.export', ['shop' => $this->shop()->slug]);
    }

    public function deletionDays(): int
    {
        return (int) Settings::get('admin.shop_deletion_days');
    }

    /** @return list<string> the shops only this person owns: their account cannot be deleted while there are any */
    public function aloneIn(): array
    {
        return app(DeleteOwnAccount::class)->alone($this->user());
    }

    public function isOperator(): bool
    {
        return (bool) $this->user()->is_operator;
    }

    public function endPlan(EndShopPlan $endPlan): void
    {
        abort_unless($this->owner(), 403);

        try {
            $endPlan->handle($this->shop(), $this->user());
        } catch (Throwable $e) {
            Log::warning('A shop\'s plan could not be ended', ['shop' => $this->shop()->id, 'error' => $e->getMessage()]);
            Notification::make()->warning()->title(__('admin::privacy.plan.failed'))->send();

            return;
        }

        $this->confirming = null;
        Notification::make()->success()->title(__('admin::privacy.plan.done'))->send();
    }

    public function deleteShop(AskToDeleteShop $ask): void
    {
        abort_unless($this->owner(), 403);
        $shop = $this->shop();
        $this->problems = [];

        if (mb_strtolower(trim($this->shopName)) !== mb_strtolower(trim((string) $shop->name))) {
            $this->problems['shopName'] = __('admin::privacy.delete.name_wrong');

            return;
        }

        try {
            $due = $ask->handle($shop, $this->user());
        } catch (Throwable $e) {
            Log::warning('A shop\'s deletion could not be asked', ['shop' => $shop->id, 'error' => $e->getMessage()]);
            Notification::make()->warning()->title(__('admin::privacy.plan.failed'))->send();

            return;
        }

        $this->confirming = null;
        $this->shopName = '';
        Notification::make()->success()->title(__('admin::privacy.delete.asked', ['date' => $due->locale(app()->getLocale())->translatedFormat('j.n.Y')]))->send();
    }

    public function keepShop(KeepShop $keep): void
    {
        abort_unless($this->owner(), 403);

        $keep->handle($this->shop(), $this->user());
        Notification::make()->success()->title(__('admin::privacy.delete.kept'))->send();
    }

    public function deleteMe(DeleteOwnAccount $delete): void
    {
        $user = $this->user();
        $this->problems = [];

        if (trim($this->phrase) !== self::PHRASE) {
            $this->problems['phrase'] = __('admin::privacy.me.phrase_wrong', ['phrase' => self::PHRASE]);

            return;
        }

        $alone = $delete->alone($user);

        if ($alone !== [] || $user->is_operator) {
            $this->problems['phrase'] = $user->is_operator ? __('admin::privacy.me.operator') : __('admin::privacy.me.alone', ['shops' => implode(', ', $alone)]);

            return;
        }

        // signed out first: signing out saves the person (a new remember token), which would write a deleted one back
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $delete->handle($user);
        $this->redirect(Filament::getLoginUrl() ?? '/', navigate: false);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
