<?php

namespace App\Modules\Sms\Support;

use App\Modules\Sms\Contracts\SmsDriver;
use App\Modules\Sms\Contracts\SmsSent;
use App\Modules\Sms\Models\SmsAccount;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 019 (019sms.co.il): a JSON POST with the account's token as a bearer, the account's user name,
 * the sender, the number and the text. Status 0 is "SMS will be sent": taken, not yet delivered.
 */
final class Sms019 implements SmsDriver
{
    public const URL = 'https://019sms.co.il/api';

    public function provider(): string
    {
        return '019';
    }

    public function send(SmsAccount $account, string $phone, string $text): SmsSent
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->acceptJson()->withToken($account->token)->post(self::URL, ['sms' => [
                'user' => ['username' => $account->username],
                'source' => $account->sender,
                'destinations' => ['phone' => $phone],
                'message' => $text,
            ]]);
        } catch (Throwable $e) {
            return new SmsSent(false, 'unreachable');
        }

        $status = $response->json('status');

        if ($response->successful() && (string) $status === '0') {
            return new SmsSent(true, null, is_scalar($response->json('shipment_id')) ? (string) $response->json('shipment_id') : null);
        }

        $message = is_string($response->json('message')) ? $response->json('message') : 'http_'.$response->status();

        return new SmsSent(false, mb_substr(trim('019 '.(is_scalar($status) ? $status.': ' : '').$message), 0, 300));
    }
}
