<?php

namespace App\Modules\Shoppers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the store's team: a shopper left a phone or an email to be called back about what they asked (shopper_callbacks),
 * with the conversation's subject and the page they asked on. Theirs to use: the shopper agreed to be contacted.
 */
final class CallbackReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly string $channel,
        public readonly string $contact,
        public readonly ?string $name,
        public readonly string $question,
        public readonly ?string $pageTitle,
        public readonly ?string $pageUrl,
        public readonly string $at,
        public readonly ?string $panelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('shoppers::mail.callback.subject', ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'shoppers::mail.callback');
    }
}
