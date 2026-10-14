<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * The first scan's structure step (docs/strict-scan.md): the catalog is divided into branches in
 * code (BranchPlan); each branch is counted (DiscoverSchema), gets a vocabulary written and
 * reviewed by a model of the other family (ProposeSchema, with what the store's own guides say),
 * a score from a pilot (ScoreSchemaProposal), and the gate (AdvanceSchemaProposal) lets it on or
 * leaves it for a person. What was already done and has not changed is not done again.
 *
 * A branch is left alone when the shop already has an active vocabulary for that category. A shop
 * whose vocabulary covers the whole catalog is left alone unless $force: its branches would read
 * the same products twice.
 *
 * Every call to a model asks SpendGuard first (inside ProposeSchema); when the cap is reached the
 * step stops there, and the branches not reached wait for the next run.
 */
final class BuildStructure
{
    public const AGENT = 'enrichment.structure';

    public const ACTION = 'enrichment.build_structure';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly DiscoverSchema $discover,
        private readonly ProposeSchema $propose,
        private readonly ScoreSchemaProposal $score,
        private readonly AdvanceSchemaProposal $advance,
    ) {}

    public function handle(string $shopId, bool $force = false): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->build($run, $shopId, $force)),
        );
    }

    private function build(RunContext $run, string $shopId, bool $force): void
    {
        $vocabularies = EnrichmentVocabulary::query()->where('active', true)->get();
        $whole = $vocabularies->contains(fn (EnrichmentVocabulary $v): bool => $v->definition()->coversWholeCatalog());

        if ($whole && ! $force) {
            $run->output(['skipped' => 'whole_catalog_vocabulary'])->summary('enrichment::runs.structure_whole');

            return;
        }

        $plan = BranchPlan::of();
        $have = $vocabularies->map(fn (EnrichmentVocabulary $v): string => $v->definition()->rootCategoryExternalId())->all();
        $counts = ['branches' => count($plan['branches']), 'already' => 0, 'proposed' => 0, 'approved' => 0, 'held' => 0, 'failed' => 0, 'unchanged' => 0, 'stopped' => 0];
        $branches = [];

        foreach ($plan['branches'] as $at => $branch) {
            if (in_array($branch['root'], $have, true)) {
                $counts['already']++;

                continue;
            }

            ['proposal' => $proposal] = $this->discover->handle($shopId, null, $branch);

            // Nothing new counted: the last proposal of this branch is where it stands.
            $proposal ??= EnrichmentSchemaProposal::query()->where('branch_key', $branch['key'])->latest()->first();

            if ($proposal === null) {
                $counts['unchanged']++;

                continue;
            }

            if ($proposal->status === EnrichmentSchemaProposal::APPROVED) {
                $counts['already']++;

                continue;
            }

            if ($proposal->status === EnrichmentSchemaProposal::DISCOVERED || $proposal->status === EnrichmentSchemaProposal::FAILED) {
                $written = $this->propose->handle($proposal);
                $proposal->refresh();

                if ($proposal->status === EnrichmentSchemaProposal::FAILED) {
                    $counts['failed']++;
                    $branches[] = ['branch' => $branch['name'], 'outcome' => 'failed'];

                    // The cap is the shop's for all branches: nothing else would be written either.
                    if ($written->summary_key === 'assistant::runs.spend_cap') {
                        $counts['stopped'] = count($plan['branches']) - $at - 1;
                        break;
                    }

                    continue;
                }

                $counts['proposed']++;
            }

            if ($proposal->status === EnrichmentSchemaProposal::PROPOSED) {
                $this->score->handle($proposal);
                $gate = $this->advance->handle($proposal->refresh());
                $counts[$gate['outcome'] === AdvanceSchemaProposal::APPROVED ? 'approved' : 'held']++;
                $branches[] = ['branch' => $branch['name'], 'score' => $proposal->score, 'outcome' => $gate['outcome'], 'why' => $gate['why']];
            }
        }

        $run->output($counts + ['rest' => $plan['rest'], 'total' => $plan['total'], 'detail' => $branches])
            ->summary('enrichment::runs.structure_built', [
                'branches' => (string) $counts['branches'],
                'approved' => (string) $counts['approved'],
                'held' => (string) $counts['held'],
                'rest' => number_format($plan['rest']),
            ]);
    }
}
