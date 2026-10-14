<?php

namespace App\Modules\Ai\Contracts;

/**
 * One question in a batch: the same prompt, user text and answer size a ChatModel call takes, with
 * an id the caller chooses so the answer can be matched back. A picture, when there is one, goes
 * with the text as it does in VisionModel.
 */
final class BatchRequest
{
    /**
     * @param  array{mime: string, bytes: string}|null  $image
     */
    public function __construct(
        public readonly string $customId,
        public readonly string $system,
        public readonly string $user,
        public readonly int $maxOutputTokens,
        public readonly ?array $image = null,
    ) {}
}
