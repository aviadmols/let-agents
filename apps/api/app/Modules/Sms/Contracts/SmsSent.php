<?php

namespace App\Modules\Sms\Contracts;

/** What came of one SMS: taken by the provider, or why not ("no_account", "bad_phone", the provider's words). */
final class SmsSent
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $reference = null,
    ) {}
}
