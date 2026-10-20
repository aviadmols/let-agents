<?php

namespace App\Modules\Runs\Support;

use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use Illuminate\Support\Facades\DB;

/**
 * One run as it went, for the operator: it began with what it was given; what code found on the way
 * (a search answer's passages and products); each call to a model, in order, with what it does (by
 * the prompt it was sent), when it began and how long it took, its model, tokens and price, and what
 * went and came back; and how it ended. Read only, from the run, its call log and the ledger.
 */
final class RunFlow
{
    /** The calls of one run shown; a run with more (a nightly batch) is read in the call log. */
    public const CALLS = 30;

    /** @var array<string, string>|null a prompt's text (its hash) => "module.name" */
    private static ?array $prompts = null;

    /**
     * @return array{steps: list<array<string, mixed>>, calls: int, cost: float, more: bool}
     */
    public static function of(Run $run): array
    {
        $started = $run->started_at ?? $run->created_at;
        $calls = $run->calls()->orderBy('id')->limit(self::CALLS + 1)->get();
        $more = $calls->count() > self::CALLS;
        $calls = $calls->take(self::CALLS);
        $ledger = DB::table('ai_usage')->where('run_id', $run->id)->orderBy('id')->get(['model', 'input_tokens', 'output_tokens', 'cost_usd'])->all();
        $steps = [['kind' => 'start', 'at' => 0.0, 'title' => __('runs::flow.start', ['trigger' => $run->trigger->label()]), 'text' => self::line($run->input)]];
        $found = self::found((array) $run->output);
        $total = 0.0;

        foreach ($calls as $call) {
            $prompt = self::prompt($call->system);
            // Code's search comes before the model writes from it.
            if ($found !== null && $prompt !== null && str_ends_with($prompt, '.answer_site')) {
                $steps[] = $found;
                $found = null;
            }
            $cost = self::cost($ledger, $call);
            $total += $cost ?? 0.0;
            $steps[] = [
                'kind' => 'call',
                'at' => $started === null ? null : max(0.0, round(($call->created_at->getTimestamp() - $started->getTimestamp()) - $call->duration_ms / 1000, 1)),
                'title' => $prompt !== null && __('runs::prompts.'.$prompt) !== 'runs::prompts.'.$prompt ? __('runs::prompts.'.$prompt) : __('runs::flow.call', ['kind' => __('runs::process.kinds.'.$call->kind)]),
                'prompt' => $prompt,
                'model' => $call->provider.' · '.$call->model,
                'tokens' => __('runs::process.tokens', ['in' => number_format($call->input_tokens), 'out' => number_format($call->output_tokens)]),
                'seconds' => round($call->duration_ms / 1000, 1),
                'cost' => $cost,
                'ok' => $call->ok,
                'error' => $call->error,
                'sent' => self::pretty($call->sent),
                'received' => self::pretty($call->received),
                'system' => $call->system,
            ];
        }

        if ($found !== null) {
            array_splice($steps, 1, 0, [$found]);
        }

        $steps[] = [
            'kind' => 'end',
            'at' => $run->duration_ms === null ? null : round($run->duration_ms / 1000, 1),
            'title' => (string) ($run->summary() ?? __('runs::flow.end')),
            'error' => $run->error,
            'status' => $run->status->label(),
        ];

        return ['steps' => $steps, 'calls' => $calls->count(), 'cost' => $run->cost_usd !== null ? (float) $run->cost_usd : $total, 'more' => $more];
    }

    /** The prompt a call was sent, as "module.name", by its text; null for one not among the released prompts. */
    public static function prompt(?string $system): ?string
    {
        if ($system === null || $system === '') {
            return null;
        }

        if (self::$prompts === null) {
            self::$prompts = [];
            foreach (glob(app_path('Modules/*/Prompts/*.md')) ?: [] as $file) {
                if (preg_match('~Modules/([^/]+)/Prompts/(.+)\.v\d+\.md$~', str_replace('\\', '/', $file), $m) === 1) {
                    self::$prompts[sha1(trim((string) file_get_contents($file)))] = mb_strtolower($m[1]).'.'.$m[2];
                }
            }
        }

        return self::$prompts[sha1(trim($system))] ?? null;
    }

    /**
     * What a search answer's code found before any model wrote (its run's "context"), as one step.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>|null
     */
    private static function found(array $output): ?array
    {
        $context = (array) ($output['context'] ?? []);

        if (! isset($context['passages_found']) && ! isset($context['products_shown'])) {
            return null;
        }

        return ['kind' => 'code', 'at' => null, 'title' => __('runs::flow.found'), 'text' => __('runs::flow.found_detail', [
            'passages' => (string) ($context['passages_found'] ?? 0),
            'passages_sent' => (string) ($context['passages_sent'] ?? 0),
            'products' => (string) ($context['products_shown'] ?? 0),
            'products_sent' => (string) ($context['products_sent'] ?? 0),
        ])];
    }

    /**
     * A call's price from the ledger: its row by model and tokens, each row taken once.
     *
     * @param  list<object>  $ledger
     */
    private static function cost(array &$ledger, RunCall $call): ?float
    {
        foreach ($ledger as $i => $row) {
            if ($row->model === $call->model && (int) $row->input_tokens === $call->input_tokens && (int) $row->output_tokens === $call->output_tokens) {
                unset($ledger[$i]);

                return $row->cost_usd === null ? null : (float) $row->cost_usd;
            }
        }

        return null;
    }

    private static function pretty(?string $text): ?string
    {
        $data = $text === null ? null : json_decode($text, true);

        return is_array($data) ? (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $text;
    }

    /** @param  array<string, mixed>|null  $input */
    private static function line(?array $input): ?string
    {
        if ($input === null || $input === []) {
            return null;
        }

        return mb_substr(implode(' · ', array_map(fn ($k, $v): string => $k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($input), $input)), 0, 300);
    }
}
