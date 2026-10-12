<?php

namespace ImageCaptionBenchmark;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\VisionDriver;

/**
 * One way of reading a picture: a first model, and the one asked when the first fails on that
 * picture (an error, no JSON, or an answer with neither a caption nor a product type). Every call
 * is kept with its tokens, cost and time, the failed ones too.
 */
final class Flow
{
    /**
     * @param  list<array{provider: string, model: string, input: float, output: float}>  $steps  first model, then fallbacks
     * @param  array<string, VisionDriver>  $drivers  by provider
     * @param  array<string, string>  $keys  by provider
     */
    public function __construct(
        public readonly string $name,
        public readonly array $steps,
        private readonly array $drivers,
        private readonly array $keys,
        private readonly string $system,
        private readonly int $maxOutputTokens,
    ) {}

    /**
     * @param  array{mime: string, data: string}  $picture
     * @return array{analysis: array<string, mixed>|null, issues: list<string>, calls: list<array<string, mixed>>, fallback: bool}
     */
    public function read(string $user, array $picture): array
    {
        $calls = [];

        foreach ($this->steps as $at => $step) {
            $started = hrtime(true);
            $call = ['step' => $at === 0 ? 'primary' : 'fallback', 'provider' => $step['provider'], 'model' => $step['model'], 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0, 'ms' => 0, 'error' => null];

            try {
                $reply = $this->drivers[$step['provider']]->jsonWithImage($this->keys[$step['provider']], $step['model'], $this->system, $user, $picture['mime'], $picture['data'], $this->maxOutputTokens);
                $call['input_tokens'] = $reply->inputTokens;
                $call['output_tokens'] = $reply->outputTokens;
                $call['cost_usd'] = $reply->costUsd($step['input'], $step['output']);
                $read = MetaBuilder::imageAnalysis($reply->data, $step['provider'], $step['model']);

                if ($read['analysis']['caption'] === '' && $read['analysis']['product_type_visible'] === null) {
                    $call['error'] = 'empty_answer';
                }
            } catch (ModelCallFailed $e) {
                $call['error'] = $e->reason.': '.mb_substr($e->getMessage(), 0, 300);
                $read = null;
            }

            $call['ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $calls[] = $call;

            if ($call['error'] === null) {
                return ['analysis' => $read['analysis'], 'issues' => $read['issues'], 'calls' => $calls, 'fallback' => $at > 0];
            }
        }

        return ['analysis' => null, 'issues' => ['failed'], 'calls' => $calls, 'fallback' => count($calls) > 1];
    }
}
