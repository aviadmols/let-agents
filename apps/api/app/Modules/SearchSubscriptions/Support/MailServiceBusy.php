<?php

namespace App\Modules\SearchSubscriptions\Support;

use RuntimeException;

/** The mail service asked us to slow down (too many requests, or the same batch already in flight): try again later. */
final class MailServiceBusy extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('the mail service is busy, retry in '.$retryAfter.'s');
    }
}
