<?php

namespace App\Modules\Admin\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the operators: a shop's owner ended the plan (a store no platform bills is cancelled by hand), asked for the
 * shop to be deleted, kept it after all, or it was deleted.
 */
final class TeamNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const PLAN_ENDED = 'plan_ended';

    public const DELETION_ASKED = 'deletion_asked';

    public const KEPT = 'kept';

    public const DELETED = 'deleted';

    public function __construct(
        public readonly string $kind,
        public readonly string $shopName,
        public readonly string $domain,
        public readonly ?string $by,
        public readonly bool $billedByHand,
        public readonly ?CarbonInterface $due = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('admin::privacy.team.'.$this->kind, ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.team-notice');
    }
}
