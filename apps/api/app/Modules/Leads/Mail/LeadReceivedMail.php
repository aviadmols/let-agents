<?php

namespace App\Modules\Leads\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the store's team: somebody left their details on the store's site (about a question it did not answer, or on a
 * page), who they are and how to reach them, what they asked, where, and a way to the store's leads in its panel.
 */
final class LeadReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{label: string, value: string}>  $answers  what they typed into the store's own fields
     */
    public function __construct(
        public readonly string $shopName,
        public readonly string $channel,
        public readonly string $contact,
        public readonly ?string $question,
        public readonly array $answers,
        public readonly ?string $pageTitle,
        public readonly ?string $pageUrl,
        public readonly string $at,
        public readonly ?string $panelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('leads::mail.subject', ['shop' => $this->shopName]));
    }

    public function content(): Content
    {
        return new Content(view: 'leads::mail.lead-received');
    }
}
