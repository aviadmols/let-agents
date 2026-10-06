<?php

namespace App\Modules\Improvement\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Improvement\Support\Digest;
use App\Modules\Improvement\Support\Evidence;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;

/**
 * Once a day per shop: what the site did not answer, what to change, and a short report.
 *
 *   1. gather     Evidence, in code: searches that found nothing or were never clicked,
 *                 questions the site could not answer. What was already reviewed is left out.
 *   2. quiet      nothing new: no model is asked, and the report says so.
 *   3. propose    the analyst (Claude by default) proposes synonyms, FAQ drafts and content
 *                 gaps, each citing its evidence by ref.
 *   4. gate       code refuses what cites no evidence, what is not this store's word, and
 *                 what was proposed before.
 *   5. audit      the auditor, from another family of models (OpenAI by default), accepts or
 *                 refuses each one. Two models that learned the same way make the same
 *                 mistakes, so the same family on both sides stops the review.
 *   6. apply      an accepted synonym joins the search when the shop allows it
 *                 (improvement.auto_synonyms); everything else waits for the team.
 *   7. report     Digest, written in code, saved and mailed when the shop gave an address.
 */
final class RunDailyReview
{
    public const AGENT = 'improvement.reviewer';

    public const ACTION = 'improvement.daily_review';

    public const PROMPT_VERSION = 'v1';

    private const PROMPTS = __DIR__.'/../Prompts/';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->review($run, $shopId)),
        );
    }

    private function review(RunContext $run, string $shopId): void
    {
        $evidence = Evidence::gather($shopId);
        $review = ImprovementReview::query()->updateOrCreate(
            ['shop_id' => $shopId, 'day' => now()->toDateString()],
            ['status' => ImprovementReview::QUIET, 'evidence' => $evidence, 'proposed' => 0, 'accepted' => 0, 'applied' => 0, 'digest' => '', 'run_id' => $run->runId],
        );

        if (Evidence::quiet($evidence)) {
            $this->finish($run, $review, $shopId, []);

            return;
        }

        $analyst = $this->role('analyst');
        $auditor = $this->role('auditor');

        if ($analyst['provider'] === null || $auditor['provider'] === null) {
            $this->stop($run, $review, $shopId, 'unknown_provider');

            return;
        }

        if ($analyst['provider']->family() === $auditor['provider']->family()) {
            $run->fail('improvement::runs.same_family', ['family' => $analyst['provider']->label()]);
            $review->update(['status' => ImprovementReview::STOPPED]);

            return;
        }

        try {
            $proposals = $this->propose($run, $shopId, $analyst, $evidence);
            $proposals = $this->audit($run, $auditor, $evidence, $proposals);
        } catch (SpendCapReached) {
            $this->stop($run, $review, $shopId, 'spend_cap');

            return;
        } catch (ModelCallFailed $e) {
            $this->stop($run, $review, $shopId, $e->reason);

            return;
        }

        $saved = [];

        foreach ($proposals as $proposal) {
            $saved[] = $this->save($shopId, $review, $proposal, $analyst, $auditor);
        }

        $review->update([
            'status' => ImprovementReview::REVIEWED,
            'proposed' => count($saved),
            'accepted' => count(array_filter($saved, fn (ImprovementProposal $p): bool => $p->status !== ImprovementProposal::REFUSED)),
            'applied' => count(array_filter($saved, fn (ImprovementProposal $p): bool => $p->status === ImprovementProposal::APPLIED)),
        ]);

        $this->finish($run, $review, $shopId, $saved);
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $analyst
     * @return list<array<string, mixed>> proposals that passed code's gates, each with its evidence
     */
    private function propose(RunContext $run, string $shopId, array $analyst, array $evidence): array
    {
        $max = (int) Settings::get('improvement.max_proposals', $shopId);
        $input = [
            'empty' => $evidence['empty'],
            'unclicked' => $evidence['unclicked'],
            'questions' => array_map(fn (array $q): array => array_diff_key($q, ['key' => true]), $evidence['questions']),
            'words' => $evidence['words'],
        ];
        $reply = $this->ask($run, 'analyst', $analyst, str_replace('{max}', (string) $max, $this->prompt('review')), $input);

        $byRef = [];

        foreach (['empty', 'unclicked', 'questions'] as $list) {
            foreach ($evidence[$list] as $item) {
                $byRef[$item['ref']] = $item;
            }
        }

        $storeText = mb_strtolower((string) SearchIndex::query()->value('items'));
        $kept = [];

        foreach (array_slice((array) ($reply->data['proposals'] ?? []), 0, $max) as $raw) {
            $proposal = $this->gate(is_array($raw) ? $raw : [], $byRef, $storeText);

            if ($proposal !== null && ! $this->proposedBefore($proposal['fingerprint'])) {
                $kept[$proposal['fingerprint']] = $proposal;
            }
        }

        return array_values($kept);
    }

    /**
     * Code's checks on one proposal: a known kind, real evidence, short fields, and for a
     * synonym a word shoppers typed that points at a word this store uses.
     *
     * @param  array<string, array<string, mixed>>  $byRef
     * @return array<string, mixed>|null
     */
    private function gate(array $raw, array $byRef, string $storeText): ?array
    {
        $kind = $raw['kind'] ?? null;
        $refs = array_values(array_filter(array_map('strval', (array) ($raw['refs'] ?? [])), fn (string $ref): bool => isset($byRef[$ref])));
        $text = fn (string $key, int $max): string => mb_substr(trim((string) ($raw[$key] ?? '')), 0, $max);

        if (! in_array($kind, ImprovementProposal::KINDS, true) || $refs === []) {
            return null;
        }

        $evidence = array_map(fn (string $ref): array => array_diff_key($byRef[$ref], ['key' => true]), $refs);

        [$title, $detail, $key] = match ($kind) {
            ImprovementProposal::SYNONYM => [$text('term', 40).' = '.$text('means', 60), ['term' => $text('term', 40), 'means' => $text('means', 60)], mb_strtolower($text('term', 40))],
            ImprovementProposal::FAQ => [$text('question', 200), ['question' => $text('question', 200), 'answer' => $text('answer', 800)], mb_strtolower($text('question', 200))],
            default => [$text('topic', 120), ['topic' => $text('topic', 120), 'why' => $text('why', 300)], mb_strtolower($text('topic', 120))],
        };

        if ($key === '' || in_array('', $detail, true)) {
            return null;
        }

        if ($kind === ImprovementProposal::SYNONYM) {
            $term = mb_strtolower($detail['term']);
            $means = mb_strtolower($detail['means']);
            $typed = implode(' ', array_map(fn (array $item): string => mb_strtolower((string) ($item['query'] ?? '')), $evidence));

            if ($term === $means || ! str_contains($typed, $term) || ! str_contains($storeText, $means)) {
                return null;
            }
        }

        return [
            'kind' => $kind,
            'title' => mb_substr($title, 0, 300),
            'detail' => $detail,
            'evidence' => $evidence,
            'fingerprint' => hash('sha256', $kind.'|'.$key),
        ];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $auditor
     * @param  list<array<string, mixed>>  $proposals
     * @return list<array<string, mixed>> the same proposals, each with a verdict and a reason
     */
    private function audit(RunContext $run, array $auditor, array $evidence, array $proposals): array
    {
        if ($proposals === []) {
            return [];
        }

        $input = [
            'proposals' => array_map(fn (array $p, int $i): array => ['id' => 'p'.($i + 1), 'kind' => $p['kind']] + $p['detail'] + ['evidence' => $p['evidence']], $proposals, array_keys($proposals)),
            'words' => $evidence['words'],
        ];
        $reply = $this->ask($run, 'auditor', $auditor, $this->prompt('audit'), $input);
        $verdicts = [];

        foreach ((array) ($reply->data['verdicts'] ?? []) as $verdict) {
            if (is_array($verdict) && isset($verdict['id'])) {
                $verdicts[(string) $verdict['id']] = $verdict;
            }
        }

        foreach ($proposals as $i => $proposal) {
            $verdict = $verdicts['p'.($i + 1)] ?? null;
            // No verdict is a refusal: nothing reaches the site that the second model did not accept.
            $proposals[$i]['accepted'] = ($verdict['verdict'] ?? null) === 'accept';
            $proposals[$i]['reason'] = mb_substr(trim((string) ($verdict['reason'] ?? '')), 0, 300) ?: null;
        }

        return $proposals;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array{provider: AiProviderName, name: string, model: string}  $analyst
     * @param  array{provider: AiProviderName, name: string, model: string}  $auditor
     */
    private function save(string $shopId, ImprovementReview $review, array $proposal, array $analyst, array $auditor): ImprovementProposal
    {
        $status = match (true) {
            ! $proposal['accepted'] => ImprovementProposal::REFUSED,
            $proposal['kind'] === ImprovementProposal::SYNONYM && Features::enabled('improvement.auto_synonyms', $shopId) => ImprovementProposal::APPLIED,
            default => ImprovementProposal::PENDING,
        };

        if ($status === ImprovementProposal::APPLIED) {
            SearchSynonym::query()->firstOrCreate(
                ['term' => $proposal['detail']['term'], 'means' => $proposal['detail']['means']],
                ['shop_id' => $shopId, 'origin' => 'review'],
            );
        }

        return ImprovementProposal::query()->create([
            'shop_id' => $shopId,
            'review_id' => $review->id,
            'kind' => $proposal['kind'],
            'title' => $proposal['title'],
            'detail' => $proposal['detail'],
            'evidence' => $proposal['evidence'],
            'fingerprint' => $proposal['fingerprint'],
            'status' => $status,
            'proposed_by' => $analyst['name'].' · '.$analyst['model'],
            'audited_by' => $auditor['name'].' · '.$auditor['model'],
            'audit_reason' => $proposal['reason'],
        ]);
    }

    /** A proposal made before is not made again, whatever became of it, unless the second model refused it. */
    private function proposedBefore(string $fingerprint): bool
    {
        return ImprovementProposal::query()->where('fingerprint', $fingerprint)->where('status', '!=', ImprovementProposal::REFUSED)->exists();
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     */
    private function ask(RunContext $run, string $name, array $role, string $system, array $input): ModelReply
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxOutput = (int) Settings::get("improvement.{$name}_max_output_tokens");
        $in = (float) Settings::get("improvement.{$name}_input_usd_per_million");
        $out = (float) Settings::get("improvement.{$name}_output_usd_per_million");

        // Hebrew runs at about two characters a token; estimating high is the safe side.
        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);

        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("improvement.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("improvement.{$name}_model"))];
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(self::PROMPTS.$name.'.'.self::PROMPT_VERSION.'.md');
    }

    private function stop(RunContext $run, ImprovementReview $review, string $shopId, string $reason): void
    {
        $review->update(['status' => ImprovementReview::STOPPED]);
        $this->finish($run, $review, $shopId, [], $reason);
    }

    /** @param list<ImprovementProposal> $proposals */
    private function finish(RunContext $run, ImprovementReview $review, string $shopId, array $proposals, ?string $stopped = null): void
    {
        $digest = Digest::write($shopId, $review->refresh(), $proposals, $stopped);
        $review->update(['digest' => $digest, 'mailed' => Digest::mail($shopId, $digest)]);

        $run->output(['status' => $review->status, 'proposed' => $review->proposed, 'accepted' => $review->accepted, 'applied' => $review->applied, 'stopped' => $stopped])
            ->summary("improvement::runs.{$review->status}", [
                'proposed' => (string) $review->proposed,
                'accepted' => (string) $review->accepted,
                'applied' => (string) $review->applied,
            ]);
    }
}
