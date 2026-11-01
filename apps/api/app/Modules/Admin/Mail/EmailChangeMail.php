<?php

namespace App\Modules\Admin\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A new email for an account, sent to that address: it becomes the account's only once its link is opened. */
final class EmailChangeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $link,
        public readonly CarbonInterface $expires,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('admin::shop_settings.email_mail.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.email-change');
    }
}
