<?php

namespace App\Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The store report by email (StoreReport): Tomer's design (emails/weekly-report.html, 12.11), in the store's language,
 * right to left in Hebrew. Daily, weekly or monthly, as its owner chose.
 */
final class StoreReportMail extends Mailable
{
    use Queueable;

    /** @param  array<string, mixed>  $report */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('admin::report.subject.'.$this->report['frequency'], [
            'searches' => number_format((int) $this->report['searches']),
            'questions' => number_format((int) $this->report['questions']),
        ], (string) $this->report['locale']));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.report');
    }
}
