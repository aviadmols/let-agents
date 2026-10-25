<?php

namespace App\Modules\Demo\Mail;

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To whoever asked for a demo, in the demo's language: the code that proves the email is theirs ('code'), or the
 * link to their store's demo once it is ready, and until when it lasts ('ready'). To the team, in the panel's
 * language: a demo that needs them, what happened and a link to the demos list ('alert', AlertDemos).
 */
final class DemoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, string> $data */
    public function __construct(
        public readonly string $kind,
        public readonly DemoSite $site,
        public readonly array $data = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('demo::mail.'.$this->kind.'.subject', ['site' => $this->site->title ?? $this->site->host, 'code' => $this->data['code'] ?? '']));
    }

    public function content(): Content
    {
        return new Content(view: 'demo::mail.demo', with: ['link' => $this->data['link'] ?? route('demo.show', $this->site->token)]);
    }
}
