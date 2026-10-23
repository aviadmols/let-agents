<?php

namespace App\Modules\Shopify\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To a shop's owners, in their panel's language: a shopper asked for what the store keeps about them, and the link
 * to download it. Nothing about the shopper is in the email itself: the download is for the owners, signed in.
 */
final class ShopperDataRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly CarbonInterface $asked,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('shopify::privacy.mail.subject', ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'shopify::mail.shopper-data');
    }
}
