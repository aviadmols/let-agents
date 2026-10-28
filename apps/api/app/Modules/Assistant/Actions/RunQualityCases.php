<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityResult;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Assistant\Support\AnswerFailure;
use App\Modules\Assistant\Support\QualityExpect;
use App\Modules\Catalog\Models\CatalogProduct;
use Throwable;

/**
 * The quality cases asked again, of one shop or every shop: a replay each, with the writer prompt at a
 * chosen version or the current one, on each case's frozen data (the same data, to compare a prompt
 * with another) or on today's catalogue and pages (to see the store as it is now). Each answer is
 * checked in code against what is expected of it; every run is kept with its numbers and each case's
 * result, so two runs can be compared. Every replay is a real model call, recorded and paid like any
 * other: run before a change goes out, not on a schedule. Stops at the spending cap and says so.
 */
final class RunQualityCases
{
    public function __construct(
        private readonly AnswerSiteQuestion $answers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  string|null  $shopId  one shop, or null for every shop's cases
     * @param  list<string>|null  $ids  some cases only
     * @param  string  $mode  AssistantQualityRun::FROZEN or LIVE
     * @param  string|null  $kind  regression, baseline, or null for both
     * @param  array<string, int>  $versions  other prompts' versions to ask with (scope_site, verify_site); the current ones when left out
     */
    public function handle(?string $shopId, ?array $ids = null, ?int $answerVersion = null, string $mode = AssistantQualityRun::FROZEN, ?string $kind = null, ?string $label = null, ?int $by = null, array $versions = []): AssistantQualityRun
    {
        $mode = $mode === AssistantQualityRun::LIVE ? AssistantQualityRun::LIVE : AssistantQualityRun::FROZEN;
        $cases = $this->tenant->runUnscoped(fn () => AssistantQualityCase::query()
            ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId))
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderBy('shop_id')->orderBy('created_at')->get());

        $run = AssistantQualityRun::query()->create([
            'shop_id' => $shopId, 'label' => $label === null ? null : mb_substr($label, 0, 120), 'mode' => $mode, 'kind' => $kind,
            'prompt_version' => $answerVersion ?? AnswerSiteQuestion::promptVersion('answer_site'), 'created_by' => $by, 'started_at' => now(),
        ]);
        $stopped = null;

        foreach ($cases as $case) {
            /** @var AssistantQualityCase $case */
            try {
                $out = $this->answers->replay((string) $case->shop_id, $case->question, (array) $case->products, $answerVersion, 'he',
                    $mode === AssistantQualityRun::FROZEN && is_array($case->snapshot) ? $case->snapshot : null, (array) $case->thread, $versions);
            } catch (SpendCapReached) {
                $stopped = 'spend_cap';
                break;
            } catch (Throwable $e) {
                report($e);
                $stopped = 'error';
                break;
            }

            $verdict = $out['answered']['verdict'] ?? null;
            $expected = array_filter((array) $case->expect, fn ($v): bool => $v !== null && $v !== [] && $v !== '' && $v !== false);
            $problems = $expected === [] ? null : QualityExpect::problems($expected, $out['result'], $verdict, $this->catalog((string) $case->shop_id, (array) ($out['result']['picks'] ?? [])));
            $result = [
                'outcome' => mb_substr((string) ($out['result']['outcome'] ?? ''), 0, 40),
                'verdict' => $verdict === null ? null : mb_substr((string) $verdict, 0, 40),
                'failure' => AnswerFailure::classify((string) ($out['result']['outcome'] ?? ''), $out['result']['reason'] ?? null, $verdict, (array) ($out['answered']['trace'] ?? [])),
                'passed' => $problems === null ? null : $problems === [],
                'problems' => $problems ?? [],
                'answer' => mb_substr((string) ($out['result']['answer'] ?? ''), 0, 2000),
                'picks' => array_values(array_map(fn ($p): string => (string) (is_array($p) ? ($p['external_id'] ?? '') : $p), (array) ($out['result']['picks'] ?? []))),
                'cost_usd' => (float) ($out['answered']['cost_usd'] ?? 0),
                'duration_ms' => $out['answered']['duration_ms'] ?? null,
                // each step's time and tokens, what was sent and how near it was: a change is measured on these too
                'trace' => $out['answered']['trace'] ?? null,
            ];

            AssistantQualityResult::query()->create(['run_id' => $run->id, 'case_id' => $case->id, 'shop_id' => $case->shop_id] + $result);
            $this->tenant->run((string) $case->shop_id, fn () => $case->forceFill([
                'last_run_at' => now(), 'last_passed' => $result['passed'], 'last_problems' => $result['problems'], 'last_answer' => $result['answer'],
                'last_outcome' => $result['outcome'], 'last_prompt_version' => $out['answered']['prompt_version'] ?? null, 'last_cost_usd' => $result['cost_usd'],
            ])->save());
        }

        $run->forceFill(['totals' => self::totals($run) + ['stopped' => $stopped, 'cases' => $cases->count()], 'finished_at' => now()])->save();

        return $run;
    }

    /**
     * The picked products as the expectations read them: title, price and description, by external id.
     *
     * @param  list<array<string, mixed>|string>  $picks
     * @return array<string, array{title: string, price: float|null, text: string}>
     */
    private function catalog(string $shopId, array $picks): array
    {
        $ids = array_values(array_filter(array_map(fn ($p): string => (string) (is_array($p) ? ($p['external_id'] ?? '') : $p), $picks)));

        if ($ids === []) {
            return [];
        }

        return $this->tenant->run($shopId, fn (): array => CatalogProduct::query()->whereIn('external_id', $ids)->get()
            ->mapWithKeys(fn (CatalogProduct $p): array => [(string) $p->external_id => [
                'title' => (string) $p->title, 'price' => $p->price === null ? null : (float) $p->price, 'text' => mb_substr(strip_tags($p->shortDescription().' '.$p->description()), 0, 4000),
            ]])->all());
    }

    /**
     * A run's numbers: how many ran, answered, were supported by the store's data (the checker kept them),
     * failed and why, passed what was expected, what they cost and took, and, step by step, where the time went
     * (the writer's and the checker's, as a mean), how much was sent (pieces, characters) and how near it was.
     *
     * @return array<string, mixed>
     */
    public static function totals(AssistantQualityRun $run): array
    {
        $results = $run->results()->get();
        $ran = $results->count();
        $answered = $results->where('outcome', AssistantAnswer::ANSWERED)->count();
        $checked = $results->whereNotNull('passed');
        $traces = $results->map(fn (AssistantQualityResult $r): array => (array) $r->trace)->filter();
        $mean = fn (string $key): ?int => ($v = $traces->pluck($key)->filter(fn ($x): bool => is_numeric($x)))->isEmpty() ? null : (int) round((float) $v->avg());
        $meanOf = fn (callable $pick): ?float => ($v = $traces->map($pick)->filter(fn ($x): bool => is_numeric($x)))->isEmpty() ? null : round((float) $v->avg(), 3);
        $durations = $results->pluck('duration_ms')->filter(fn ($x): bool => is_numeric($x))->sort()->values();
        $at = fn (float $share): ?int => $durations->isEmpty() ? null : (int) $durations[(int) min($durations->count() - 1, floor($share * $durations->count()))];

        return [
            'p50_ms' => $at(0.5),
            'p90_ms' => $at(0.9),
            'writer_ms' => $mean('writer_ms'),
            'check_ms' => $mean('check_ms'),
            'writer_in' => $meanOf(fn (array $t): ?int => isset($t['writer_tokens'][0]) ? (int) $t['writer_tokens'][0] : null),
            'check_out' => $meanOf(fn (array $t): ?int => isset($t['check_tokens'][1]) ? (int) $t['check_tokens'][1] : null),
            'passages' => $meanOf(fn (array $t): ?int => isset($t['context']['passages_sent']) ? (int) $t['context']['passages_sent'] : null),
            'chars' => $meanOf(fn (array $t): ?int => isset($t['context']['chars']) ? (int) $t['context']['chars'] : null),
            // the nearest and the least near piece sent, as a mean over the cases that sent any
            'score_top' => $meanOf(fn (array $t): ?float => ($t['context']['scores'] ?? []) === [] ? null : max($t['context']['scores'])),
            'score_low' => $meanOf(fn (array $t): ?float => ($t['context']['scores'] ?? []) === [] ? null : min($t['context']['scores'])),
        ] + [
            'ran' => $ran,
            'answered' => $ran > 0 ? round(100 * $answered / $ran, 1) : null,
            'supported' => $answered > 0 ? round(100 * $results->where('outcome', AssistantAnswer::ANSWERED)->where('verdict', 'kept')->count() / $answered, 1) : null,
            'unanswered' => $ran > 0 ? round(100 * $results->whereIn('outcome', [AssistantAnswer::NO_INFO, 'no_match'])->count() / $ran, 1) : null,
            'failures' => $results->whereNotNull('failure')->countBy('failure')->all(),
            'passed' => $checked->where('passed', true)->count(),
            'expected' => $checked->count(),
            'cost_usd' => round((float) $results->sum('cost_usd'), 6),
            'cost_per_answer' => $answered > 0 ? round((float) $results->sum('cost_usd') / $answered, 6) : null,
            'ms' => $ran > 0 ? (int) round((float) $results->avg('duration_ms')) : null,
        ];
    }
}
