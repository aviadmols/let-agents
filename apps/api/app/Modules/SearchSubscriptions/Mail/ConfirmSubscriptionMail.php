<?php

namespace App\Modules\SearchSubscriptions\Mail;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email A: confirm the subscription. Nothing is sent to the address unless its button is pressed. */
final class ConfirmSubscriptionMail extends SubscriptionMail
{
    public function __construct(public readonly SearchSubscription $subscription, string $storeName)
    {
        parent::__construct($subscription->shop_id, $storeName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender(), replyTo: $this->replyAddresses(),
            subject: 'Confirm your subscription to “'.Subscribers::cut($this->subscription->query, 40).'”');
    }

    public function content(): Content
    {
        return new Content(view: 'search_subscriptions::mail.confirm', with: [
            'store' => $this->storeName,
            'logo' => (string) Settings::get('search_subscriptions.logo_url', $this->shopId),
            'query' => Subscribers::cut($this->subscription->query, 80),
            'confirmUrl' => Subscribers::confirmUrl($this->subscription),
        ]);
    }
}
