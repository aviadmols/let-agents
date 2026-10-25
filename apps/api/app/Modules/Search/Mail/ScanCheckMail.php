<?php

namespace App\Modules\Search\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the team, after a store's scan was checked (CheckStoreSearch): what was tried, what failed, what was mended for
 * this store alone (or would have been, in "dry" mode), how the assistant answered, and what it cost.
 */
final class ScanCheckMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $report */
    public function __construct(
        public readonly string $store,
        public readonly string $domain,
        public readonly array $report,
        public readonly string $runId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('search::mail.scan_check.subject', ['store' => $this->store, 'failed' => (string) $this->report['failed'], 'tried' => (string) $this->report['tried']]));
    }

    public function content(): Content
    {
        return new Content(view: 'search::mail.scan-check', with: ['link' => route('filament.operator.resources.runs.view', $this->runId)]);
    }
}
