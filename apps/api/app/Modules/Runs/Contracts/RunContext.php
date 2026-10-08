<?php

namespace App\Modules\Runs\Contracts;

/**
 * What the work inside a run can report. Collected in memory and written once when the work
 * ends, so a run is never half-updated.
 */
final class RunContext
{
    private ?string $summaryKey = null;

    /** @var array<string, scalar> */
    private array $summaryParams = [];

    /** @var array<string, mixed> */
    private array $output = [];

    private bool $failed = false;

    private ?string $error = null;

    /** @var array{provider: string, model: string, input_tokens: int, output_tokens: int, cache_read_tokens: int, cost_usd: float|null}|null */
    private ?array $usage = null;

    /** @var list<array{provider: string, model: string, input_tokens: int, output_tokens: int, cache_read_tokens: int, cost_usd: float|null}> every model call, for the ledger */
    private array $calls = [];

    public function __construct(public readonly string $runId) {}

    /**
     * @param  string  $key  translation key, e.g. "connections::runs.connected"
     * @param  array<string, scalar>  $params
     */
    public function summary(string $key, array $params = []): self
    {
        $this->summaryKey = $key;
        $this->summaryParams = $params;

        return $this;
    }

    /** @param array<string, mixed> $data merged into what was reported before */
    public function output(array $data): self
    {
        $this->output = array_replace($this->output, $data);

        return $this;
    }

    /**
     * Marks the run failed without throwing: an expected failure, such as a wrong token.
     *
     * @param  array<string, scalar>  $params
     */
    public function fail(string $summaryKey, array $params = [], ?string $error = null): self
    {
        $this->failed = true;
        $this->error = $error;

        return $this->summary($summaryKey, $params);
    }

    /** Tokens and cost of a model call. Adds up when a run calls a model more than once. */
    public function usage(string $provider, string $model, int $inputTokens = 0, int $outputTokens = 0, int $cacheReadTokens = 0, ?float $costUsd = null): self
    {
        $previous = $this->usage;
        $this->calls[] = ['provider' => $provider, 'model' => $model, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'cache_read_tokens' => $cacheReadTokens, 'cost_usd' => $costUsd];

        $this->usage = [
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => ($previous['input_tokens'] ?? 0) + $inputTokens,
            'output_tokens' => ($previous['output_tokens'] ?? 0) + $outputTokens,
            'cache_read_tokens' => ($previous['cache_read_tokens'] ?? 0) + $cacheReadTokens,
            'cost_usd' => $costUsd === null && ($previous['cost_usd'] ?? null) === null
                ? null
                : (float) ($previous['cost_usd'] ?? 0) + (float) $costUsd,
        ];

        return $this;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * @return array{summary_key: ?string, summary_params: array<string, scalar>, output: array<string, mixed>, error: ?string, usage: ?array<string, mixed>, calls: list<array<string, mixed>>}
     */
    public function report(): array
    {
        return [
            'summary_key' => $this->summaryKey,
            'summary_params' => $this->summaryParams,
            'output' => $this->output,
            'error' => $this->error,
            'usage' => $this->usage,
            'calls' => $this->calls,
        ];
    }
}
