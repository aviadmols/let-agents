<?php

namespace App\Modules\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The Monday email to the shop owner: the week on one page (WeeklySummary). First sketch (8.11): rendered for the owner
 * to see from Today ("See an example"); not sent yet, until its settings and schedule are decided.
 */
final class WeeklySummaryMail extends Mailable
{
    use Queueable;

    /** @param  array<string, mixed>  $week */
    public function __construct(public readonly array $week) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('admin::weekly.subject', ['shop' => $this->week['shop'], 'week' => $this->week['week']]));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.weekly');
    }
}
