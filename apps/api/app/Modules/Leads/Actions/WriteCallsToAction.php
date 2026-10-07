<?php

namespace App\Modules\Leads\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Models\LeadReview;
use App\Modules\Leads\Support\CtaComposer;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * Two models, arguing, with code holding the door at both ends.
 *
 * One model writes lines for a page. A second scores them out of a hundred and says why, and is
 * told to be harder to please than the writer was. Between and around them the code refuses
 * anything that promises a result, invents urgency, or says a number the page does not contain —
 * because a prompt can be talked round and a regular expression cannot.
 *
 * The reviewer is not the last word either. Every score it gives is kept beside what readers
 * afterwards did with that line, so the reviewer itself can be measured: a critic whose scores do
 * not predict anything is a critic worth replacing, and that only becomes visible if the scores
 * are written down.
 *
 * A line scored between the floor and the bar gets one more attempt, with the reviewer's own
 * rewrite handed back to the writer. One more, not a conversation: two models talking to each
 * other until they agree is two models agreeing with each other.
 */
final class WriteCallsToAction
{
    public const AGENT = 'leads.writer';

    public const ACTION = 'leads.write_ctas';

    /** Below this a line is not worth a second attempt either. */
    private const FLOOR = 40;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, int $limit): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['limit' => $limit],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->write($run, $shopId, $limit)),
        );
    }

    private function write(RunContext $run, string $shopId, int $limit): void
    {
        $flow = LeadFlow::inForce($shopId);

        if ($flow === null || $limit < 1) {
            $run->output(['pages' => 0])->summary('leads::runs.no_flow');

            return;
        }

        $writer = (string) Settings::get('leads.writer_model');
        $reviewer = (string) Settings::get('leads.reviewer_model');
        $bar = (int) Settings::get('leads.reviewer_bar');
        $rules = $flow->rules();

        $counts = ['pages' => 0, 'written' => 0, 'refused_by_code' => 0, 'refused_by_reviewer' => 0, 'second_tries' => 0, 'kept' => 0, 'stopped' => null];

        foreach ($this->pagesWorthWriting($limit) as $page) {
            try {
                $this->spend->assertCanSpend($this->estimate());
                $counts['pages']++;
                $kept = $this->forPage($run, $page, $flow, $rules, $writer, $reviewer, $bar, $counts);
            } catch (SpendCapReached) {
                $counts['stopped'] = 'spend_cap';

                break;
            } catch (ModelCallFailed $e) {
                $counts['stopped'] = $e->reason;

                break;
            }

            $counts['kept'] += $kept;
        }

        $run->output($counts)->summary('leads::runs.written', [
            'pages' => number_format($counts['pages']),
            'kept' => number_format($counts['kept']),
            'refused' => number_format($counts['refused_by_code'] + $counts['refused_by_reviewer']),
        ]);
    }

    /**
     * One page through the whole council.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $counts
     */
    private function forPage(RunContext $run, array $page, LeadFlow $flow, array $rules, string $writer, string $reviewer, int $bar, array &$counts): int
    {
        $lines = $this->ask($run, $writer, 'write_cta', [
            'page' => $page['about'],
            'offer' => $flow->offer,
            'goal' => $flow->goal->value,
            'existing' => $page['existing'],
        ]);

        $lines = $this->throughTheGate($lines, $flow->offer, $rules, $counts);

        if ($lines === []) {
            return 0;
        }

        $scored = $this->review($run, $reviewer, $page, $flow, $lines);
        $kept = 0;
        $retry = [];

        foreach ($scored as $line) {
            if ($line['score'] >= $bar) {
                $this->keep($page, $flow, $line, $writer, $reviewer);
                $kept++;

                continue;
            }

            $counts['refused_by_reviewer']++;

            // Close but wrong, and the reviewer said how: one more attempt, its words.
            if ($line['score'] >= self::FLOOR && trim((string) $line['better']) !== '') {
                $retry[] = ['headline' => trim((string) $line['better']), 'why' => 'reviewer rewrite'];
            }
        }

        if ($retry !== []) {
            $counts['second_tries']++;
            $retry = $this->throughTheGate($retry, $flow->offer, $rules, $counts);

            foreach ($this->review($run, $reviewer, $page, $flow, $retry) as $line) {
                if ($line['score'] >= $bar) {
                    $this->keep($page, $flow, $line, $writer, $reviewer);
                    $kept++;
                }
            }
        }

        return $kept;
    }

    /**
     * What code will not let through, whichever model wrote it.
     *
     * @param  list<array{headline: string, why: string}>  $lines
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $counts
     * @return list<array{headline: string, why: string}>
     */
    private function throughTheGate(array $lines, string $offer, array $rules, array &$counts): array
    {
        $allowed = [];

        foreach ($lines as $line) {
            $headline = trim((string) ($line['headline'] ?? ''));

            if ($headline === '') {
                continue;
            }

            if (CtaComposer::reject(['headline' => $headline, 'body' => $offer], $rules) !== null) {
                $counts['refused_by_code']++;

                continue;
            }

            $allowed[] = ['headline' => $headline, 'why' => (string) ($line['why'] ?? '')];
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array{headline: string, why: string}>  $lines
     * @return list<array{headline: string, score: int, reasons: list<string>, better: string}>
     */
    private function review(RunContext $run, string $reviewer, array $page, LeadFlow $flow, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $reply = $this->ask($run, $reviewer, 'review_cta', [
            'page' => $page['about'],
            'offer' => $flow->offer,
            'lines' => $lines,
        ], 'scores');

        $byHeadline = [];

        foreach ($reply as $row) {
            $headline = trim((string) ($row['headline'] ?? ''));

            if ($headline !== '') {
                $byHeadline[$headline] = [
                    'headline' => $headline,
                    'score' => max(0, min(100, (int) ($row['score'] ?? 0))),
                    'reasons' => array_slice(array_map('strval', (array) ($row['reasons'] ?? [])), 0, 4),
                    'better' => (string) ($row['better'] ?? ''),
                ];
            }
        }

        // A line the reviewer did not return is a line nobody vouched for.
        $scored = [];

        foreach ($lines as $line) {
            if (isset($byHeadline[$line['headline']])) {
                $scored[] = $byHeadline[$line['headline']];
            }
        }

        return $scored;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array{headline: string, score: int, reasons: list<string>, better: string}  $line
     */
    private function keep(array $page, LeadFlow $flow, array $line, string $writer, string $reviewer): void
    {
        $cta = LeadCta::query()->updateOrCreate(
            [
                'shop_id' => $flow->shop_id,
                'page_type' => $page['type'],
                'page_external_id' => $page['external_id'],
                // A written line is its own shape, so it never displaces the code's.
                'variant' => 'written_'.substr(hash('sha256', $line['headline']), 0, 8),
            ],
            [
                'headline' => $line['headline'],
                'body' => $flow->offer,
                'source' => 'model',
                'model' => $writer,
                'score' => $line['score'],
                'review' => ['by' => $reviewer, 'reasons' => $line['reasons']],
                'active' => true,
                'composed_at' => now(),
            ],
        );

        // Kept apart from the line itself, because this is what the reviewer is judged on later.
        LeadReview::query()->create([
            'shop_id' => $flow->shop_id,
            'cta_id' => $cta->id,
            'writer' => $writer,
            'reviewer' => $reviewer,
            'score' => $line['score'],
            'reasons' => $line['reasons'],
            'reviewed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function ask(RunContext $run, string $model, string $prompt, array $input, string $key = 'lines'): array
    {
        $role = $prompt === 'write_cta' ? 'writer' : 'reviewer';
        $provider = AgentModel::provider("leads.{$role}_provider");
        $reply = $this->models->json(
            $provider,
            $model,
            (string) file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v1.md'),
            (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int) Settings::get('leads.model_tokens'),
        );

        $this->record($run, $model, $reply, $role, $provider->value);

        return array_values(array_filter((array) ($reply->data[$key] ?? []), 'is_array'));
    }

    private function record(RunContext $run, string $model, ModelReply $reply, string $role, string $provider): void
    {
        $run->usage($provider, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd(
            (float) Settings::get("leads.{$role}_input_usd_per_million"),
            (float) Settings::get("leads.{$role}_output_usd_per_million"),
        ));
    }

    /** Roughly a writer call and a reviewer call for one page. */
    private function estimate(): float
    {
        $tokens = (int) Settings::get('leads.model_tokens');

        return $tokens * ((float) Settings::get('leads.writer_output_usd_per_million') + (float) Settings::get('leads.reviewer_output_usd_per_million')) / 1_000_000;
    }

    /**
     * The pages worth spending a model on: the ones whose only offer is the generic one.
     *
     * A page the templates already fitted does not need a model, and a page nobody visits does
     * not need one either — but visits are the learning's business, so the simple rule is used
     * first and the learning refines it later.
     *
     * @return iterable<array{type: string, external_id: string, about: array<string, mixed>, existing: list<string>}>
     */
    private function pagesWorthWriting(int $limit): iterable
    {
        $written = LeadCta::query()->where('source', 'model')->pluck('page_external_id')->unique()->all();

        $pages = LeadCta::query()
            ->where('active', true)
            ->whereNotIn('page_external_id', $written)
            ->get()
            ->groupBy(fn (LeadCta $cta): string => $cta->page_type.'|'.$cta->page_external_id)
            ->take($limit);

        foreach ($pages as $key => $group) {
            [$type, $externalId] = explode('|', (string) $key, 2);

            yield [
                'type' => $type,
                'external_id' => $externalId,
                'about' => ['title' => $group->first()->headline, 'subject' => null, 'audience' => null, 'points' => 0, 'question' => null],
                'existing' => $group->pluck('headline')->all(),
            ];
        }
    }
}
