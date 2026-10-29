<?php

namespace App\Modules\Alerts\Mail;

use App\Modules\Alerts\Support\Problem;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the team, in the panel's language: what went wrong in the system, the most urgent first, each with what happens
 * now and where to look (TellProblems). Sent at once, not queued: a queue that stopped is one of the problems.
 */
final class SystemAlertMail extends Mailable
{
    use Queueable;

    /** @param  list<Problem>  $problems */
    public function __construct(public readonly array $problems) {}

    public function envelope(): Envelope
    {
        $urgent = array_filter($this->problems, fn (Problem $p): bool => $p->urgent) !== [];
        $subject = count($this->problems) === 1
            ? __('alerts::mail.subject_one', ['title' => $this->problems[0]->title])
            : __('alerts::mail.subject_many', ['count' => count($this->problems)]);

        return new Envelope(subject: ($urgent ? __('alerts::mail.urgent').' ' : '').$subject);
    }

    public function content(): Content
    {
        return new Content(view: 'alerts::mail.alert');
    }
}
