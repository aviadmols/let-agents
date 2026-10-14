<?php

namespace App\Modules\Ai\Contracts;

/** A picture a model made: its bytes and type, how long the provider worked, and its own id for the log. */
final class ImageReply
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly float $seconds,
        public readonly ?string $predictionId = null,
    ) {}
}
