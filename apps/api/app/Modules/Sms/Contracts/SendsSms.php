<?php

namespace App\Modules\Sms\Contracts;

/**
 * Sends one SMS from the platform's account, and logs it. A module that needs to text a shopper
 * (a sign-in code) asks this, never a provider.
 */
interface SendsSms
{
    /** Whether an active account is set up to send from. */
    public function ready(): bool;

    /**
     * @param  string  $phone  as the shopper typed it; normalized here
     * @param  string  $purpose  what it is for, for the log ("wishlist_code")
     */
    public function send(string $phone, string $text, string $purpose, ?string $shopId = null): SmsSent;
}
