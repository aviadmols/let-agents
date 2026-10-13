<?php

namespace App\Modules\Sms\Support;

use App\Modules\Sms\Contracts\Phone;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Sms\Contracts\SmsDriver;
use App\Modules\Sms\Contracts\SmsSent;
use App\Modules\Sms\Models\SmsAccount;
use App\Modules\Sms\Models\SmsMessage;

/** Sends from the active account through its provider's driver, and logs every try. */
final class SmsService implements SendsSms
{
    /** @param  iterable<SmsDriver>  $drivers */
    public function __construct(private readonly iterable $drivers) {}

    public function ready(): bool
    {
        return $this->account() !== null;
    }

    public function send(string $phone, string $text, string $purpose, ?string $shopId = null): SmsSent
    {
        $account = $this->account();
        $number = Phone::normalize($phone);
        $driver = $account === null ? null : $this->driver($account->provider);

        $sent = match (true) {
            $account === null || $driver === null => new SmsSent(false, 'no_account'),
            $number === null => new SmsSent(false, 'bad_phone'),
            default => $driver->send($account, $number, $text),
        };

        SmsMessage::query()->create([
            'shop_id' => $shopId,
            'provider' => $account?->provider ?? '—',
            'purpose' => mb_substr($purpose, 0, 40),
            'phone_masked' => Phone::masked($number ?? $phone),
            'ok' => $sent->ok,
            'error' => $sent->error,
            'provider_ref' => $sent->reference,
            'created_at' => now(),
        ]);

        if ($account !== null && $number !== null) {
            $account->forceFill($sent->ok ? ['last_sent_at' => now(), 'last_error' => null] : ['last_error' => $sent->error])->save();
        }

        return $sent;
    }

    private function account(): ?SmsAccount
    {
        return SmsAccount::query()->where('active', true)->orderBy('created_at')->first();
    }

    private function driver(string $provider): ?SmsDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver;
            }
        }

        return null;
    }
}
