<?php

namespace App\Modules\Sms\Contracts;

use App\Modules\Sms\Models\SmsAccount;

/** One SMS provider: a new one is a class tagged "sms.drivers" (SmsServiceProvider). */
interface SmsDriver
{
    /** The provider's key, as the account row names it ("019"). */
    public function provider(): string;

    /** @param  string  $phone  05XXXXXXXX */
    public function send(SmsAccount $account, string $phone, string $text): SmsSent;
}
