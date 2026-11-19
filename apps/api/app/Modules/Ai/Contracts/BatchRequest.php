<?php

namespace App\Modules\Ai\Contracts;

/**
 * One question in a batch: the same prompt, user text and answer size a ChatModel call takes, with
 * an id the caller chooses so the answer can be matched back. A picture, when there is one, goes
 * with the text as it does in VisionModel. A schema, when there is one, holds the reply to it as
 * StructuredChatModel does (Anthropic's output_config.format, OpenAI's strict json_schema), and an
 * effort is passed on as a chat call's is (adaptive thinking on a model that takes it).
 */
final class BatchRequest
{
    /**
     * @param  array{mime: string, bytes: string}|null  $image
     * @param  array<string, mixed>|null  $schema  every object closed, every property required
     * @param  string|null  $effort  low, medium or high
     */
    public function __construct(
        public readonly string $customId,
        public readonly string $system,
        public readonly string $user,
        public readonly int $maxOutputTokens,
        public readonly ?array $image = null,
        public readonly ?array $schema = null,
        public readonly ?string $effort = null,
    ) {}
}
