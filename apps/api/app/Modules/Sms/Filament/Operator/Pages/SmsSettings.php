<?php

namespace App\Modules\Sms\Filament\Operator\Pages;

use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Sms\Models\SmsAccount;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The SMS account the platform sends from (019), for the operator only: the user name, the token
 * (kept encrypted, shown only as its last characters), the sender name, a test message, and what
 * was sent this month, shop by shop.
 */
final class SmsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'sms';

    protected string $view = 'sms::operator.settings';

    public string $username = '';

    /** Empty keeps the saved token. */
    public string $token = '';

    public string $sender = 'LETS';

    public bool $active = true;

    public string $testPhone = '';

    public static function getNavigationLabel(): string
    {
        return __('sms::ui.title');
    }

    public function getTitle(): string
    {
        return __('sms::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('sms::ui.subheading');
    }

    public function mount(): void
    {
        $account = $this->account();
        $this->username = (string) ($account?->username ?? '');
        $this->sender = (string) ($account?->sender ?? 'LETS');
        $this->active = (bool) ($account?->active ?? true);
    }

    public function account(): ?SmsAccount
    {
        return SmsAccount::query()->where('provider', '019')->first();
    }

    public function save(): void
    {
        $sender = trim($this->sender);
        $username = trim($this->username);
        $token = trim($this->token);
        $account = $this->account();

        if ($username === '' || preg_match('/^[A-Za-z0-9]{1,11}$/', $sender) !== 1 || ($account === null && $token === '')) {
            Notification::make()->warning()->title(__('sms::ui.invalid'))->body(__('sms::ui.invalid_body'))->send();

            return;
        }

        $values = ['username' => $username, 'sender' => $sender, 'active' => $this->active];
        if ($token !== '') {
            $values += ['token' => $token, 'token_hint' => '…'.substr($token, -4)];
        }

        $account === null
            ? SmsAccount::query()->create(['provider' => '019'] + $values)
            : $account->forceFill($values)->save();

        $this->token = '';
        Notification::make()->success()->title(__('sms::ui.saved'))->send();
    }

    public function test(): void
    {
        $sent = app(SendsSms::class)->send($this->testPhone, (string) __('sms::ui.test_text'), 'test');

        $sent->ok
            ? Notification::make()->success()->title(__('sms::ui.test_sent'))->send()
            : Notification::make()->danger()->title(__('sms::ui.test_failed'))->body((string) $sent->error)->persistent()->send();
    }

    /** @return Collection<int, object> this month's messages by shop: sent and failed */
    public function month(): Collection
    {
        $names = Shop::query()->pluck('name', 'id');

        return SmsMessage::query()->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('shop_id, sum(case when ok then 1 else 0 end) as sent, sum(case when ok then 0 else 1 end) as failed')
            ->groupBy('shop_id')->orderByDesc('sent')->toBase()->get()
            ->map(function (object $row) use ($names): object {
                $row->name = $row->shop_id === null ? __('sms::ui.platform') : ($names[$row->shop_id] ?? '—');

                return $row;
            });
    }

    /** @return Collection<int, SmsMessage> */
    public function latest(): Collection
    {
        return SmsMessage::query()->latest('id')->limit(20)->get();
    }
}
