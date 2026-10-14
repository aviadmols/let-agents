<?php

namespace App\Modules\Runs\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Contracts\CallJournal;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use Throwable;

/** Writes each model call to run_calls, under the run open at the time or the shop in context. */
final class CallLog implements CallJournal
{
    /** Long enough for any prompt the platform sends; a runaway reply is cut, not refused. */
    private const MAX_CHARS = 200_000;

    public function __construct(
        private readonly RunStack $stack,
        private readonly TenantContext $tenant,
    ) {}

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
    ): void {
        try {
            $run = $this->stack->current();

            RunCall::query()->create([
                'run_id' => $run['id'] ?? null,
                'shop_id' => $run['shop_id'] ?? $this->tenant->id(),
                'agent' => $run['agent'] ?? null,
                'action' => $run['action'] ?? null,
                'kind' => $kind,
                'provider' => $provider,
                'model' => $model,
                'system' => $system === null ? null : self::cut($system),
                'sent' => self::cut($sent),
                'received' => $output === null ? null : self::cut((string) json_encode(Run::redact($output), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)),
                'error' => $error === null ? null : self::cut($error, 4000),
                'ok' => $error === null,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'duration_ms' => max(0, $durationMs),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function cut(string $text, int $max = self::MAX_CHARS): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
