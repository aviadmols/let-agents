<?php

namespace App\Modules\SearchSubscriptions\Mail;

use App\Core\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Headers;

/**
 * What every email of Search Subscriptions shares: Lets sends it in the store's name (the From name is the store's, the
 * address Lets' own), replies go to the store's address when it gave one, and, where it stops something, the headers
 * Gmail and Outlook show their own unsubscribe button for (List-Unsubscribe, List-Unsubscribe-Post). Always in English.
 */
abstract class SubscriptionMail extends Mailable
{
    use Queueable;

    /** @var array{0: string, 1: string}|null the one-tap link a mail client posts to, and the page a person opens */
    protected ?array $unsubscribe = null;

    public function __construct(public readonly string $shopId, public readonly string $storeName)
    {
        $this->locale('en');
    }

    protected function sender(): Address
    {
        $name = trim($this->storeName) !== '' ? $this->storeName : (string) config('mail.from.name');

        return new Address((string) config('mail.from.address'), mb_substr($name, 0, 80));
    }

    /** @return list<Address> */
    protected function replyAddresses(): array
    {
        $to = trim((string) Settings::get('search_subscriptions.reply_to', $this->shopId));

        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false ? [new Address($to, mb_substr($this->storeName, 0, 80))] : [];
    }

    public function headers(): Headers
    {
        if ($this->unsubscribe === null) {
            return new Headers;
        }
        [$post, $page] = $this->unsubscribe;

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$post.'>, <'.$page.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
