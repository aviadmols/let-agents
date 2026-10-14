<?php

namespace App\Modules\Ai\Contracts;

/** One answer out of a batch: the reply, or why there is none. */
final class BatchResult
{
    public function __construct(
        public readonly string $customId,
        public readonly ?ModelReply $reply,
        public readonly ?string $error = null,
    ) {}
}
