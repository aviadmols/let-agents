<?php

namespace App\Modules\Alerts\Support;

use DateTimeInterface;

/**
 * One thing in the system that went wrong, as the team reads it in an email: what happened, what happens now or
 * what is theirs to do, and where to look. The key names the problem itself, so the same one is told once
 * (TellProblems) until $until, or for the hours alerts.repeat_hours says; a key that carries a month or a cap is told
 * again when either changes.
 */
final class Problem
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $what,
        public readonly string $next,
        public readonly ?string $link = null,
        public readonly bool $urgent = false,
        public readonly ?DateTimeInterface $until = null,
    ) {}
}
