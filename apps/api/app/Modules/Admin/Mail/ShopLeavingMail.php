<?php

namespace App\Modules\Admin\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To a shop's owners, in their panel's language: the shop will be deleted on a day, and the link that keeps it
 * until then ('asked'); or it was kept after all ('kept').
 */
final class ShopLeavingMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const ASKED = 'asked';

    public const KEPT = 'kept';

    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly ?string $askedBy,
        public readonly ?CarbonInterface $due,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('admin::privacy.mail.'.$this->kind.'.subject', ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.shop-leaving');
    }
}
