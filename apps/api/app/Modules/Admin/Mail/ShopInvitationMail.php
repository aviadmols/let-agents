<?php

namespace App\Modules\Admin\Mail;

use App\Modules\Admin\Enums\ShopRole;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The invitation to a shop's panel: who invited, as what, and the link to set a password and come in. */
final class ShopInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly ?string $inviter,
        public readonly ShopRole $role,
        public readonly string $link,
        public readonly CarbonInterface $expires,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('admin::invitations.mail.subject', ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.invitation');
    }
}
