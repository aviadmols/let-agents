<?php

namespace App\Modules\Ai\Contracts;

/** Where a submitted batch is: still being worked on, ended with answers to collect, or lost. */
final class BatchState
{
    public const RUNNING = 'running';

    /** Every request was answered or refused; the results can be read. */
    public const ENDED = 'ended';

    /** The provider gave up on the whole batch (failed, expired, cancelled): nothing to read. */
    public const LOST = 'lost';

    public function __construct(
        public readonly string $status,
        public readonly int $total,
        public readonly int $done,
        public readonly int $failed,
        public readonly ?string $detail = null,
    ) {}
}
