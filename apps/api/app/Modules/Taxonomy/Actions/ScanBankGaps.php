<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyProposal;
use App\Modules\Taxonomy\Models\TaxonomySubject;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Models;
use App\Modules\Taxonomy\Support\Norm;
use App\Modules\Taxonomy\Support\Subjects;

/**
 * What the bank lacks, read from the catalogue rather than from a few examples.
 *
 * 1. Code finds the tagged products none of whose tags in the bank's inferred groups (topic, genre,
 *    occasion) is central to them (weight under taxonomy.gap_weight_percent): what the bank does not
 *    yet describe. At most taxonomy.gap_products, spread through the catalogue.
 * 2. A model (the bank's reviewer) reads them with the bank and proposes the concepts they are about
 *    that no tag means, each with the products that are it.
 * 3. Code keeps a proposal once per group and wording, never one a tag of the group already holds
 *    under those words; MergeTagProposals then decides each, by a model of the other family.
 */
final class ScanBankGaps
{
    public const AGENT = 'taxonomy.bank_builder';

    public const ACTION = 'taxonomy.scan_gaps';

    public const PROMPT_VERSION = 1;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->scan($run, $shopId)),
        );
    }

    private function scan(RunContext $run, string $shopId): void
    {
        $groups = Bank::groups();
        $inferred = $groups->where('kind', TaxonomyGroup::INFERRED)->pluck('id')->all();

        if ($inferred === []) {
            $run->output(['skipped' => 'no_inferred_groups'])->summary('taxonomy::runs.no_bank');

            return;
        }

        // the products tagged, and of them those with no central tag of an inferred group
        $min = (int) Settings::get('taxonomy.gap_weight_percent', $shopId) / 100;
        $covered = TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->where('weight', '>=', $min)->whereHas('tag', fn ($q) => $q->whereIn('group_id', $inferred))->distinct()->pluck('subject_id')->all();
        $tagged = TaxonomySubject::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->orderBy('subject_id')->pluck('subject_id')->all();
        $open = array_values(array_diff($tagged, $covered));
        $max = (int) Settings::get('taxonomy.gap_products', $shopId);
        // spread through the catalogue, not its first products
        $step = max(1, (int) floor(count($open) / max(1, $max)));
        $picked = array_slice(array_values(array_filter($open, fn ($id, int $i): bool => $i % $step === 0, ARRAY_FILTER_USE_BOTH)), 0, $max);

        if ($picked === []) {
            $run->output(['tagged' => count($tagged), 'uncovered' => 0])->summary('taxonomy::runs.gaps', ['uncovered' => '0', 'proposed' => '0']);

            return;
        }

        $items = Subjects::products(CatalogProduct::query()->whereIn('id', $picked)->orderBy('id')->get());
        $given = TaxonomyAssignment::query()->whereIn('subject_id', $picked)->where('status', TaxonomyAssignment::KEPT)->with('tag')->get()->groupBy('subject_id');
        $byRef = [];
        $input = [];
        foreach (array_values($items) as $i => $item) {
            $byRef[$ref = 'i'.($i + 1)] = $item;
            $input[] = ['id' => $ref, 'title' => $item['title'], 'groups' => $item['groups'], 'tags' => $given->get($item['id'], collect())->map(fn (TaxonomyAssignment $a): string => $a->tag->label_he)->values()->all(), 'text' => mb_substr($item['text'], 0, 400)];
        }

        $reply = $this->models->call($run, 'bank_gaps', self::PROMPT_VERSION, 'bank_reviewer', [
            'bank' => $groups->map(fn (TaxonomyGroup $g): array => ['group' => $g->key, 'name' => $g->label_he.' / '.$g->label_en, 'kind' => $g->kind, 'tags' => $g->tags->pluck('label_he')->all()])->values()->all(),
            'items' => $input,
        ]);
        if ($reply === null) {
            return;
        }

        $byKey = $groups->keyBy('key');
        $count = ['proposed' => 0, 'known' => 0];
        foreach ((array) ($reply->data['proposals'] ?? []) as $p) {
            $group = $byKey->get((string) ($p['group'] ?? ''));
            $he = mb_substr(trim((string) ($p['label_he'] ?? '')), 0, 120);
            $subjects = array_values(array_filter(array_map(fn ($ref): ?array => isset($byRef[(string) $ref]) ? [TaxonomyAssignment::PRODUCT, $byRef[(string) $ref]['id']] : null, (array) ($p['items'] ?? []))));
            if ($group === null || $he === '' || $subjects === []) {
                continue;
            }
            $norm = Norm::label($he);
            if ($group->tags->contains(fn (TaxonomyTag $t): bool => in_array($norm, array_map(Norm::label(...), [$t->label_he, $t->label_en, ...(array) $t->words]), true))) {
                $count['known']++;

                continue;
            }
            $proposal = TaxonomyProposal::query()->firstOrNew(['group_id' => $group->id, 'norm' => $norm], [
                'shop_id' => $shopId, 'label_he' => $he, 'label_en' => mb_substr(trim((string) ($p['label_en'] ?? $he)), 0, 120),
                'why' => mb_substr(trim((string) ($p['why'] ?? '')), 0, 300), 'subjects' => [],
            ]);
            $proposal->subjects = array_values(array_unique([...(array) $proposal->subjects, ...$subjects], SORT_REGULAR));
            $proposal->save();
            $count['proposed']++;
        }

        $run->output(['tagged' => count($tagged), 'uncovered' => count($open), 'read' => count($picked)] + $count)
            ->summary('taxonomy::runs.gaps', ['uncovered' => (string) count($open), 'proposed' => (string) $count['proposed']]);
    }
}
