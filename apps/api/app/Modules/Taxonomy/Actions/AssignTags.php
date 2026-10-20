<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
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
 * Tags a store's products and posts from its bank, only those that changed since they were tagged.
 *
 * 1. Code reads each product and post (Subjects) and its hash; one whose hash is the one it was
 *    tagged with is left as it is (all: every one again).
 * 2. One family tags them, taxonomy.batch_size at a time, the bank first and the same every call so
 *    the provider can cache it: each tag with where it was read from (the store's words, the picture,
 *    what is known of a well-known product or work), the evidence and how sure; and the tags the
 *    bank lacks, proposed.
 * 3. Code keeps only tags of the bank, of a group whose scope holds the product's categories. A
 *    factual tag (what a product is made of, measures, holds) stands only on the store's words or
 *    the picture, quoted as they are: else it is dropped. An inferred one whose words are not quoted,
 *    or less sure than taxonomy.keep_min, or from knowledge less sure than taxonomy.knowledge_min,
 *    waits for review. A group that is not multiple keeps its surest.
 * 4. A model of the other family checks taxonomy.audit_percent of the batches: what it finds clearly
 *    wrong is rejected, with why.
 * 5. Each product's tags are replaced with what it was given now; a proposal is kept once per group
 *    and wording, with what it was proposed for, for MergeTagProposals.
 *
 * Every call asks SpendGuard first and is recorded on one run, with what was kept, dropped and why.
 */
final class AssignTags
{
    public const AGENT = 'taxonomy.tagger';

    public const ACTION = 'taxonomy.assign';

    /** assign_tags and check_tags */
    public const PROMPT_VERSION = 1;

    /** @var array<string, int> */
    private array $count = [];

    /** @var array<string, TaxonomyGroup> */
    private array $groups = [];

    /** @var array<string, array<string, TaxonomyTag>> group key => tag key => tag */
    private array $tags = [];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
    ) {}

    /** @param  list<string>|null  $productIds  only these (catalog ids) */
    public function handle(string $shopId, ?int $limit = null, bool $all = false, bool $posts = true, ?array $productIds = null, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['limit' => $limit, 'all' => $all, 'posts' => $posts, 'products' => $productIds === null ? null : count($productIds)],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->assign($run, $shopId, $limit, $all, $posts, $productIds)),
        );
    }

    private function assign(RunContext $run, string $shopId, ?int $limit, bool $all, bool $posts, ?array $productIds): void
    {
        $this->count = array_fill_keys(['subjects', 'calls', 'kept', 'review', 'rejected', 'dropped_unknown', 'dropped_scope', 'dropped_unsupported', 'unquoted', 'catalog', 'picture', 'knowledge', 'proposed', 'proposals_known', 'audited'], 0);
        $groups = Bank::groups();

        if ($groups->isEmpty()) {
            $run->output(['skipped' => 'no_bank'])->summary('taxonomy::runs.no_bank');

            return;
        }
        if (! Models::crossed('tagger', 'tag_checker')) {
            $run->fail('taxonomy::runs.same_family');

            return;
        }

        $this->groups = $groups->keyBy('key')->all();
        $this->tags = $groups->mapWithKeys(fn (TaxonomyGroup $g): array => [$g->key => $g->tags->keyBy('key')->all()])->all();
        $prefix = Bank::prefix($groups);
        $version = Bank::version();
        $todo = $this->todo($limit, $all, $posts, $productIds);
        $size = max(1, (int) Settings::get('taxonomy.batch_size', $shopId));

        foreach (array_chunk($todo, $size, true) as $batch) {
            if (! $this->batch($run, $shopId, $batch, $prefix, $version)) {
                break;
            }
        }

        $run->output(['bank_version' => $version] + $this->count)
            ->summary('taxonomy::runs.assigned', ['subjects' => (string) $this->count['subjects'], 'kept' => (string) $this->count['kept'], 'review' => (string) $this->count['review'], 'rejected' => (string) $this->count['rejected']]);
    }

    /**
     * What needs tagging: what changed since it was tagged, products first.
     *
     * @param  list<string>|null  $productIds
     * @return array<string, array<string, mixed>> by subject id
     */
    private function todo(?int $limit, bool $all, bool $posts, ?array $productIds): array
    {
        $done = TaxonomySubject::query()->get(['subject_type', 'subject_id', 'input_hash'])->mapWithKeys(fn (TaxonomySubject $s): array => [$s->subject_type.':'.$s->subject_id => $s->input_hash])->all();
        $todo = [];
        $wanted = fn (array $item): bool => $all || ($done[$item['type'].':'.$item['id']] ?? null) !== $item['hash'];

        $query = CatalogProduct::query()->whereNull('removed_at')->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))->orderBy('id');
        foreach ($query->lazyById(200) as $product) {
            foreach (Subjects::products(collect([$product])) as $id => $item) {
                if ($wanted($item)) {
                    $todo[$id] = $item;
                }
            }
            if ($limit !== null && count($todo) >= $limit) {
                return $todo;
            }
        }

        if ($posts && $productIds === null) {
            foreach (CatalogContent::query()->whereNull('removed_at')->orderBy('id')->lazyById(200) as $post) {
                foreach (Subjects::posts(collect([$post])) as $id => $item) {
                    if ($wanted($item)) {
                        $todo[$id] = $item;
                    }
                }
                if ($limit !== null && count($todo) >= $limit) {
                    break;
                }
            }
        }

        return $todo;
    }

    /** @param  array<string, array<string, mixed>>  $batch  by subject id */
    private function batch(RunContext $run, string $shopId, array $batch, string $prefix, int $version): bool
    {
        $byRef = [];
        foreach (array_values($batch) as $i => $item) {
            $byRef['i'.($i + 1)] = $item;
        }

        $this->count['calls']++;
        $reply = $this->models->call($run, 'assign_tags', self::PROMPT_VERSION, 'tagger', [
            'items' => array_map(fn (string $ref, array $item): array => ['id' => $ref] + array_diff_key($item, ['type' => true, 'id' => true, 'hash' => true]), array_keys($byRef), $byRef),
        ], $prefix);

        if ($reply === null) {
            return false;
        }

        $given = [];
        $proposals = [];
        $dropped = [];
        foreach ((array) ($reply->data['items'] ?? []) as $out) {
            $ref = (string) ($out['id'] ?? '');
            if (! isset($byRef[$ref]) || isset($given[$ref])) {
                continue;
            }
            [$given[$ref], $dropped[$ref]] = $this->kept($byRef[$ref], (array) ($out['tags'] ?? []));
            $proposals[$ref] = (array) ($out['propose'] ?? []);
        }

        if (random_int(1, 100) <= (int) Settings::get('taxonomy.audit_percent', $shopId) && array_filter($given) !== []) {
            if (! $this->audit($run, $byRef, $given)) {
                return false;
            }
        }

        foreach ($byRef as $ref => $item) {
            $this->save($shopId, $item, $given[$ref] ?? [], $dropped[$ref] ?? 0, $version, $run->runId);
            $this->propose($shopId, $item, $proposals[$ref] ?? []);
            $this->count['subjects']++;
        }

        return true;
    }

    /**
     * 3: the tags code keeps of what the model gave one item, and how many it dropped.
     *
     * @param  array<string, mixed>  $item
     * @param  list<mixed>  $tags
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function kept(array $item, array $tags): array
    {
        $keepMin = (float) Settings::get('taxonomy.keep_min_percent') / 100;
        $knowledgeMin = (float) Settings::get('taxonomy.knowledge_min_percent') / 100;
        $catalog = Subjects::catalogText($item);
        // a scope is of the store's product categories: a post, whose categories are the blog's, is held to none
        $categories = $item['type'] === TaxonomyAssignment::CONTENT ? [] : $item['groups'];
        $kept = [];
        $dropped = 0;

        foreach ($tags as $t) {
            $groupKey = (string) ($t['group'] ?? '');
            $tag = $this->tags[$groupKey][(string) ($t['tag'] ?? '')] ?? null;
            $group = $this->groups[$groupKey] ?? null;
            $source = (string) ($t['source'] ?? '');
            $evidence = mb_substr(trim((string) ($t['evidence'] ?? '')), 0, 400);
            $confidence = max(0.0, min(1.0, (float) ($t['confidence'] ?? 0)));

            if ($tag === null || $group === null || ! in_array($source, TaxonomyAssignment::SOURCES, true) || isset($kept[$tag->id])) {
                $this->count['dropped_unknown']++;
                $dropped++;

                continue;
            }
            if (! $group->appliesTo($categories)) {
                $this->count['dropped_scope']++;
                $dropped++;

                continue;
            }

            $quoted = match ($source) {
                'catalog' => Norm::quoted($evidence, $catalog),
                'picture' => $item['picture'] !== '' && Norm::quoted($evidence, $item['picture']),
                default => false,
            };

            // a factual tag only on the store's words or the picture, as they are
            if ($group->kind === TaxonomyGroup::FACTUAL && ! $quoted) {
                $this->count['dropped_unsupported']++;
                $dropped++;

                continue;
            }

            $review = $confidence < $keepMin || ($source === 'knowledge' && $confidence < $knowledgeMin);
            if ($source !== 'knowledge' && ! $quoted) {
                $this->count['unquoted']++;
                $review = true;
            }

            // said outright: the store's own words hold the tag's name or one of its other words; else inferred
            $explicit = $source !== 'knowledge' && collect([$tag->label_he, $tag->label_en, ...(array) $tag->words])
                ->contains(fn ($w): bool => mb_strlen((string) $w) >= 3 && Norm::quoted((string) $w, $source === 'picture' ? $item['picture'] : $catalog));

            $kept[$tag->id] = ['tag' => $tag, 'group' => $group, 'source' => $source, 'evidence' => $evidence, 'confidence' => $confidence,
                'weight' => max(0.0, min(1.0, (float) ($t['weight'] ?? 0.5))), 'explicit' => $explicit,
                'status' => $review ? TaxonomyAssignment::REVIEW : TaxonomyAssignment::KEPT, 'checked' => null, 'note' => null];
        }

        // a group that is not multiple keeps its surest
        $best = [];
        foreach ($kept as $id => $k) {
            if (! $k['group']->multiple) {
                $other = $best[$k['group']->key] ?? null;
                if ($other !== null && $kept[$other]['confidence'] >= $k['confidence']) {
                    unset($kept[$id]);
                    $dropped++;

                    continue;
                }
                if ($other !== null) {
                    unset($kept[$other]);
                    $dropped++;
                }
                $best[$k['group']->key] = $id;
            }
        }

        return [array_values($kept), $dropped];
    }

    /**
     * 4: the other family's check of what was kept.
     *
     * @param  array<string, array<string, mixed>>  $byRef
     * @param  array<string, list<array<string, mixed>>>  $given  changed in place
     */
    private function audit(RunContext $run, array $byRef, array &$given): bool
    {
        $items = [];
        $refs = [];
        foreach ($given as $ref => $tags) {
            if ($tags === []) {
                continue;
            }
            $lines = [];
            foreach ($tags as $i => $k) {
                $refs[$ref]['t'.($i + 1)] = $i;
                $lines[] = ['ref' => 't'.($i + 1), 'group' => $k['group']->label_he.' / '.$k['group']->label_en, 'kind' => $k['group']->kind, 'tag' => $k['tag']->label_he.' / '.$k['tag']->label_en, 'source' => $k['source'], 'evidence' => $k['evidence']];
            }
            $items[] = ['id' => $ref] + array_diff_key($byRef[$ref], ['type' => true, 'id' => true, 'hash' => true]) + ['tags' => $lines];
        }

        $this->count['audited'] += count($items);
        $checked = $this->models->call($run, 'check_tags', self::PROMPT_VERSION, 'tag_checker', ['items' => $items]);

        if ($checked === null) {
            return false;
        }

        foreach ($given as $ref => $tags) {
            foreach ($tags as $i => $k) {
                $given[$ref][$i]['checked'] = 'held';
            }
        }
        foreach ((array) ($checked->data['wrong'] ?? []) as $w) {
            $i = $refs[(string) ($w['id'] ?? '')][(string) ($w['ref'] ?? '')] ?? null;
            if ($i !== null) {
                $given[(string) $w['id']][$i] = ['status' => TaxonomyAssignment::REJECTED, 'checked' => 'taken_out', 'note' => mb_substr(trim((string) ($w['why'] ?? '')), 0, 200)] + $given[(string) $w['id']][$i];
            }
        }

        return true;
    }

    /**
     * 5: the item's tags, replaced.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $tags
     */
    private function save(string $shopId, array $item, array $tags, int $dropped, int $version, string $runId): void
    {
        TaxonomyAssignment::query()->where('subject_type', $item['type'])->where('subject_id', $item['id'])->delete();

        foreach ($tags as $k) {
            TaxonomyAssignment::query()->create([
                'shop_id' => $shopId, 'subject_type' => $item['type'], 'subject_id' => $item['id'], 'tag_id' => $k['tag']->id,
                'source' => $k['source'], 'evidence' => $k['evidence'] ?: null, 'confidence' => round($k['confidence'], 3), 'weight' => round($k['weight'], 3), 'explicit' => $k['explicit'], 'status' => $k['status'],
                'checked' => $k['checked'], 'check_note' => $k['note'], 'input_hash' => $item['hash'], 'bank_version' => $version, 'run_id' => $runId,
            ]);
            $this->count[$k['status']]++;
            $this->count[$k['source']]++;
        }

        TaxonomySubject::query()->updateOrCreate(['subject_type' => $item['type'], 'subject_id' => $item['id']], [
            'shop_id' => $shopId, 'input_hash' => $item['hash'], 'bank_version' => $version, 'dropped' => $dropped, 'run_id' => $runId,
        ]);
    }

    /**
     * Tags the bank lacks, kept once per group and wording with what they were proposed for; one the
     * group already holds under these words is not kept.
     *
     * @param  array<string, mixed>  $item
     * @param  list<mixed>  $proposed
     */
    private function propose(string $shopId, array $item, array $proposed): void
    {
        foreach (array_slice($proposed, 0, 2) as $p) {
            $group = $this->groups[(string) ($p['group'] ?? '')] ?? null;
            $he = mb_substr(trim((string) ($p['label_he'] ?? '')), 0, 120);
            if ($group === null || $he === '' || ! $group->appliesTo($item['type'] === TaxonomyAssignment::CONTENT ? [] : $item['groups'])) {
                continue;
            }
            $norm = Norm::label($he);
            $known = collect($this->tags[$group->key])->contains(fn (TaxonomyTag $t): bool => in_array($norm, array_map(Norm::label(...), [$t->label_he, $t->label_en, ...(array) $t->words]), true));
            if ($known) {
                $this->count['proposals_known']++;

                continue;
            }

            $proposal = TaxonomyProposal::query()->firstOrNew(['group_id' => $group->id, 'norm' => $norm], [
                'shop_id' => $shopId, 'label_he' => $he, 'label_en' => mb_substr(trim((string) ($p['label_en'] ?? $he)), 0, 120),
                'why' => mb_substr(trim((string) ($p['why'] ?? '')), 0, 300), 'subjects' => [],
            ]);
            $subjects = (array) $proposal->subjects;
            if (! in_array([$item['type'], $item['id']], $subjects, true)) {
                $subjects[] = [$item['type'], $item['id']];
            }
            $proposal->subjects = $subjects;
            $proposal->save();
            $this->count['proposed']++;
        }
    }
}
