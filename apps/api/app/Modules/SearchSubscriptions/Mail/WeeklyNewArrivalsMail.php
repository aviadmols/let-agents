<?php

namespace App\Modules\SearchSubscriptions\Mail;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email B: the week's new, in-stock products for all of one address's searches in one store, at most once a week.
 * Each search with its products and a one-tap "Stop this search"; the foot manages or stops them all.
 */
final class WeeklyNewArrivalsMail extends SubscriptionMail
{
    /**
     * @param  list<array{query: string, stop: string, products: list<array{title: string, price: string, image: string, url: string}>}>  $searches
     */
    public function __construct(string $shopId, string $storeName, public readonly string $sendId, public readonly string $emailHash,
        public readonly array $searches, public readonly string $storeUrl)
    {
        parent::__construct($shopId, $storeName);
        $this->unsubscribe = [Subscribers::oneClickUrl($shopId, $emailHash), Subscribers::stopAllUrl($shopId, $emailHash)];
    }

    public function total(): int
    {
        return array_sum(array_map(fn (array $s): int => count($s['products']), $this->searches));
    }

    public function envelope(): Envelope
    {
        $first = Subscribers::cut((string) ($this->searches[0]['query'] ?? ''), 40);
        $more = count($this->searches) > 1 ? ' and more' : '';

        return new Envelope(from: $this->sender(), replyTo: $this->replyAddresses(),
            subject: $this->total().' new for “'.$first.'”'.$more.' · '.Subscribers::cut($this->storeName, 40));
    }

    public function content(): Content
    {
        return new Content(view: 'search_subscriptions::mail.weekly', with: [
            'store' => $this->storeName,
            'logo' => (string) Settings::get('search_subscriptions.logo_url', $this->shopId),
            'total' => $this->total(),
            'searches' => $this->searches,
            'storeUrl' => $this->storeUrl === '' ? '' : Subscribers::clickUrl($this->sendId, Subscribers::tagged($this->storeUrl)),
            'manageUrl' => Subscribers::manageUrl($this->shopId, $this->emailHash),
            'stopAllUrl' => Subscribers::stopAllUrl($this->shopId, $this->emailHash),
            'pixel' => Subscribers::openPixelUrl($this->sendId),
        ]);
    }
}
