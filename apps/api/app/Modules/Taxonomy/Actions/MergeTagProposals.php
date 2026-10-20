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
use Illuminate\Database\Eloquent\Builder;

/**
 * The bank grows from what tagging found it lacks, not by itself.
 *
 * 1. Code takes the open proposals made for at least taxonomy.min_support products or posts (one, by
 *    default: how many is no reason a concept is right, its relevance and grounding are); the
 *    same wording in a group was already one proposal (AssignTags), and one the group holds under
 *    those words was never kept.
 * 2. A model of the other family than the tagger's decides each, group by group, against the
 *    group's tags: add it, merge it into a tag that means it, or reject it. Code applies what names
 *    ids and keys it gave; an added tag comes in with the bank's next version.
 * 3. Only what may now take the tag is tagged again: what it was proposed for, and the products
 *    whose title or description holds its words. Their record is cleared, so AssignTags reads them.
 */
final class MergeTagProposals
{
    public const AGENT = 'taxonomy.tagger';

    public const ACTION = 'taxonomy.merge_proposals';

    public const PROMPT_VERSION = 2;

    /** Products a new tag's words are looked for in, at most. */
    private const RETAG_MAX = 500;

    /** Titles of what a tag was proposed for, sent with it, at most. */
    private const TITLES = 5;

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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->merge($run, $shopId)),
        );
    }

    private function merge(RunContext $run, string $shopId): void
    {
        $min = (int) Settings::get('taxonomy.min_support', $shopId);
        $open = TaxonomyProposal::query()->where('status', TaxonomyProposal::OPEN)->orderBy('id')->get()
            ->filter(fn (TaxonomyProposal $p): bool => count((array) $p->subjects) >= $min)->groupBy('group_id');
        $count = ['added' => 0, 'merged' => 0, 'rejected' => 0, 'retag' => 0];

        if ($open->isEmpty()) {
            $run->output($count + ['skipped' => 'none'])->summary('taxonomy::runs.merged', ['added' => '0', 'merged' => '0', 'rejected' => '0']);

            return;
        }
        if (! Models::crossed('tagger', 'bank_writer')) {
            $run->fail('taxonomy::runs.same_family');

            return;
        }

        $version = Bank::version() + 1;
        $retag = [];

        foreach ($open as $groupId => $proposals) {
            $group = TaxonomyGroup::query()->with(['tags' => fn ($q) => $q->where('status', TaxonomyTag::ACTIVE)])->find($groupId);
            if ($group === null) {
                continue;
            }
            $byRef = [];
            foreach ($proposals->values() as $i => $p) {
                $byRef['p'.($i + 1)] = $p;
            }

            $reply = $this->models->call($run, 'merge_proposals', self::PROMPT_VERSION, 'bank_writer', [
                'group' => ['key' => $group->key, 'label' => $group->label_he.' / '.$group->label_en, 'kind' => $group->kind,
                    'tags' => $group->tags->map(fn (TaxonomyTag $t): array => ['key' => $t->key, 'label_he' => $t->label_he, 'label_en' => $t->label_en, 'words' => (array) $t->words])->values()->all()],
                'proposals' => array_map(fn (string $ref, TaxonomyProposal $p): array => ['id' => $ref, 'label_he' => $p->label_he, 'label_en' => $p->label_en, 'why' => (string) $p->why,
                    'count' => count((array) $p->subjects), 'for' => $this->titles((array) $p->subjects)], array_keys($byRef), $byRef),
            ]);
            if ($reply === null) {
                return;
            }

            $tags = $group->tags->keyBy('key');
            foreach ((array) ($reply->data['decisions'] ?? []) as $d) {
                $proposal = $byRef[(string) ($d['id'] ?? '')] ?? null;
                if ($proposal === null || $proposal->status !== TaxonomyProposal::OPEN) {
                    continue;
                }
                $note = mb_substr(trim((string) ($d['why'] ?? '')), 0, 200);
                $action = (string) ($d['action'] ?? '');
                $tag = null;

                if ($action === 'merge' && ($into = $tags->get((string) ($d['into'] ?? ''))) !== null) {
                    $into->words = array_slice(array_values(array_unique([...(array) $into->words, $proposal->label_he, $proposal->label_en])), 0, 10);
                    $into->save();
                    [$tag, $status] = [$into, 'merged'];
                } elseif ($action === 'add') {
                    $key = Norm::key((string) ($d['key'] ?? '') ?: $proposal->label_en);
                    $tag = $tags->get($key) ?? TaxonomyTag::query()->create([
                        'shop_id' => $shopId, 'group_id' => $group->id, 'key' => $key,
                        'label_he' => mb_substr(trim((string) ($d['label_he'] ?? '')) ?: $proposal->label_he, 0, 120),
                        'label_en' => mb_substr(trim((string) ($d['label_en'] ?? '')) ?: $proposal->label_en, 0, 120),
                        'words' => array_slice(array_values(array_filter(array_map(fn ($w): string => mb_substr(trim((string) $w), 0, 60), (array) ($d['words'] ?? [])))), 0, 6) ?: null,
                        'status' => TaxonomyTag::ACTIVE, 'version' => $version,
                    ]);
                    $tags->put($key, $tag);
                    $status = 'added';
                } else {
                    $status = 'rejected';
                }

                $proposal->forceFill(['status' => $status, 'tag_id' => $tag?->id, 'decision_note' => $note])->save();
                $count[$status]++;

                if ($tag !== null) {
                    foreach ((array) $proposal->subjects as [$type, $id]) {
                        $retag[$type.':'.$id] = [$type, $id];
                    }
                    foreach ($this->holding($tag) as $id) {
                        $retag[TaxonomyAssignment::PRODUCT.':'.$id] = [TaxonomyAssignment::PRODUCT, $id];
                    }
                }
            }
        }

        foreach ($retag as [$type, $id]) {
            TaxonomySubject::query()->where('subject_type', $type)->where('subject_id', $id)->delete();
        }
        $count['retag'] = count($retag);

        $run->output($count + ['bank_version' => Bank::version()])->summary('taxonomy::runs.merged', ['added' => (string) $count['added'], 'merged' => (string) $count['merged'], 'rejected' => (string) $count['rejected']]);
    }

    /**
     * The titles of some of what a tag was proposed for, so it is judged by what those products are.
     *
     * @param  list<array{0: string, 1: string}>  $subjects
     * @return list<string>
     */
    private function titles(array $subjects): array
    {
        $ids = array_slice($subjects, 0, self::TITLES);
        $products = CatalogProduct::query()->whereIn('id', array_column(array_filter($ids, fn (array $s): bool => $s[0] === TaxonomyAssignment::PRODUCT), 1))->pluck('title')->all();
        $posts = CatalogContent::query()->whereIn('id', array_column(array_filter($ids, fn (array $s): bool => $s[0] === TaxonomyAssignment::CONTENT), 1))->pluck('title')->all();

        return array_values(array_map('strval', [...$products, ...$posts]));
    }

    /** @return list<string> catalog ids of the products whose title or description holds the tag's words */
    private function holding(TaxonomyTag $tag): array
    {
        $words = array_values(array_filter([$tag->label_he, $tag->label_en, ...(array) $tag->words], fn ($w): bool => mb_strlen((string) $w) >= 3));

        if ($words === []) {
            return [];
        }

        return CatalogProduct::query()->whereNull('removed_at')
            ->where(function (Builder $q) use ($words): void {
                foreach ($words as $w) {
                    $q->orWhere('title', 'like', '%'.$w.'%')->orWhere('payload->description', 'like', '%'.$w.'%');
                }
            })->limit(self::RETAG_MAX)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }
}
