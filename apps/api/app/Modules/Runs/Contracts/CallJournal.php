<?php

namespace App\Modules\Runs\Contracts;

/**
 * Where a model call is written down as it was: the prompt, what was sent, what came back or the
 * error, the tokens and how long it took. The Ai module writes every call here; the run it
 * belongs to (if any) is the one running at the time. Kept for runs.call_log_days, operator only.
 *
 * Never throws: a journal that cannot write must not fail the call it describes.
 */
interface CallJournal
{
    public const CHAT = 'chat';

    public const VISION = 'vision';

    public const WEB = 'web';

    public const IMAGE = 'image';

    /**
     * @param  string  $kind  one of the constants above
     * @param  array<string, mixed>|null  $output  the reply as received, null when the call failed
     */
    public function record(
        string $kind,
        string $provider,
        string $model,
        ?string $system,
        string $sent,
        ?array $output,
        ?string $error,
        int $inputTokens,
        int $outputTokens,
        int $durationMs,
    ): void;
}
