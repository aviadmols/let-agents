<?php

namespace App\Modules\SearchSubscriptions\Mail;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email C: a subscription ends in a few days. One tap keeps it for another round; doing nothing lets it stop. */
final class StillInterestedMail extends SubscriptionMail
{
    public function __construct(public readonly SearchSubscription $subscription, string $storeName, public readonly int $keepDays)
    {
        parent::__construct($subscription->shop_id, $storeName);
        $this->unsubscribe = [Subscribers::oneClickUrl($subscription->shop_id, $subscription->email_hash), Subscribers::stopUrl($subscription)];
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender(), replyTo: $this->replyAddresses(),
            subject: 'Still interested in “'.Subscribers::cut($this->subscription->query, 40).'”?');
    }

    public function content(): Content
    {
        $ends = $this->subscription->expires_at;

        return new Content(view: 'search_subscriptions::mail.remind', with: [
            'store' => $this->storeName,
            'logo' => (string) Settings::get('search_subscriptions.logo_url', $this->shopId),
            'query' => Subscribers::cut($this->subscription->query, 80),
            'subscribed' => ($this->subscription->confirmed_at ?? $this->subscription->created_at)->format('j M'),
            'ends' => $ends?->format('j M') ?? '',
            'days' => $ends === null ? 0 : max(1, (int) ceil(now()->diffInDays($ends))),
            'keepDays' => $this->keepDays,
            'keepUrl' => Subscribers::keepUrl($this->subscription),
            'stopUrl' => Subscribers::stopUrl($this->subscription),
            'manageUrl' => Subscribers::manageUrl($this->subscription->shop_id, $this->subscription->email_hash),
        ]);
    }
}
