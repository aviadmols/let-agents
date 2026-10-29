<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyRule;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Models;
use App\Modules\Taxonomy\Support\Norm;
use Illuminate\Support\Collection;

/**
 * A rule the shop wrote of its products ("the book 'X' does not belong with books like 'Y'; it goes with the
 * biographies"), read into a plan of what changes, for the team to approve (ApplyRule). Code first: the products
 * the rule may be about are found by their titles, the categories and the store's tags it names, with the tags each
 * has now. A writer reads the rule against them and answers in JSON against a schema: what it understood, and for
 * each product the tags to add or take off and the products to keep apart or put together. Code keeps only products
 * and tags it was given. A checker of the other family reads the plan against the rule; what it finds wrong is taken
 * out. A rule that names nothing the store has, or that the writer cannot act on, comes back as a question to the
 * team. The same rule against the same products is read once.
 */
final class ReadRule
{
    public const AGENT = 'taxonomy.rule_reader';

    public const ACTION = 'taxonomy.read_rule';

    public const WRITE_VERSION = 1;

    public const CHECK_VERSION = 1;

    /** Changes a rule may make at most: a rule of a whole category stays readable on the screen. */
    private const MAX_CHANGES = 120;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
    ) {}

    public function handle(string $shopId, string $ruleId): void
    {
        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['rule' => $ruleId],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $ruleId)),
        );
    }

    private function read(RunContext $run, string $ruleId): void
    {
        $rule = TaxonomyRule::query()->find($ruleId);

        if ($rule === null || $rule->status !== TaxonomyRule::READING) {
            $run->output(['rule' => $ruleId, 'skipped' => 'gone'])->summary('taxonomy::runs.rule_gone');

            return;
        }

        if (! Models::crossed('rule_writer', 'rule_checker')) {
            $rule->forceFill(['status' => TaxonomyRule::FAILED, 'note' => __('taxonomy::runs.rule_failed_note')])->save();
            $run->fail('taxonomy::runs.rule_same_family', ['family' => self::who('rule_writer')]);

            return;
        }

        $groups = Bank::groups();
        $tags = self::tagsByKey($groups);
        $candidates = $this->candidates($rule->text);

        if ($candidates === []) {
            $rule->forceFill(['status' => TaxonomyRule::UNCLEAR, 'note' => __('taxonomy::runs.rule_nothing_named')])->save();
            $run->output(['rule' => $ruleId, 'products' => 0])->summary('taxonomy::runs.rule_unclear');

            return;
        }

        $byId = [];
        $forModel = [];
        $current = self::currentTags(array_map(fn (CatalogProduct $p): string => (string) $p->id, $candidates), $tags);

        foreach ($candidates as $product) {
            $byId[(string) $product->external_id] = $product;
            $forModel[] = [
                'id' => (string) $product->external_id,
                'title' => (string) $product->title,
                'categories' => $product->categories->pluck('name')->map(fn ($n): string => (string) $n)->values()->all(),
                'store_tags' => self::storeTags($product),
                'tags' => $current[(string) $product->id] ?? [],
            ];
        }

        // the same rule against the same products, with the same tags, is read once
        $fingerprint = hash('sha256', json_encode([self::WRITE_VERSION, self::CHECK_VERSION, trim($rule->text), array_map(fn (array $p): array => [$p['id'], $p['tags']], $forModel)], JSON_UNESCAPED_UNICODE));
        $before = TaxonomyRule::query()->where('fingerprint', $fingerprint)->whereKeyNot($rule->id)->whereNotNull('plan')->latest('id')->first();

        if ($before !== null) {
            $rule->forceFill(['status' => TaxonomyRule::PROPOSED, 'note' => $before->note, 'plan' => $before->plan, 'fingerprint' => $fingerprint, 'proposed_by' => $before->proposed_by, 'audited_by' => $before->audited_by])->save();
            $run->output(['rule' => $ruleId, 'products' => count($forModel), 'read_before' => $before->id])->summary('taxonomy::runs.rule_read_again');

            return;
        }

        $reply = $this->models->call($run, 'read_rule', self::WRITE_VERSION, 'rule_writer', ['rule' => $rule->text, 'products' => $forModel], Bank::prefix($groups));

        if ($reply === null) {
            $rule->forceFill(['status' => TaxonomyRule::FAILED, 'note' => __('taxonomy::runs.rule_failed_note')])->save();

            return;
        }

        $understood = mb_substr(trim((string) ($reply->data['understood'] ?? '')), 0, TaxonomyRule::MAX_TEXT);
        $question = mb_substr(trim((string) ($reply->data['question'] ?? '')), 0, TaxonomyRule::MAX_TEXT);
        $changes = self::gate((array) ($reply->data['changes'] ?? []), $byId, $tags, $current);

        if ($changes === []) {
            $rule->forceFill(['status' => TaxonomyRule::UNCLEAR, 'note' => $question !== '' ? $question : ($understood !== '' ? $understood : __('taxonomy::runs.rule_unclear_note')), 'fingerprint' => $fingerprint, 'proposed_by' => self::who('rule_writer')])->save();
            $run->output(['rule' => $ruleId, 'products' => count($forModel), 'changes' => 0])->summary('taxonomy::runs.rule_unclear');

            return;
        }

        $check = $this->models->call($run, 'check_rule', self::CHECK_VERSION, 'rule_checker', [
            'rule' => $rule->text,
            'understood' => $understood,
            'changes' => array_map(fn (array $c): array => ['id' => $c['external_id'], 'title' => $c['title'], 'add' => $c['add'], 'remove' => $c['remove'], 'apart' => $c['apart'], 'alike' => $c['alike']], $changes),
        ]);

        if ($check === null) {
            $rule->forceFill(['status' => TaxonomyRule::FAILED, 'note' => __('taxonomy::runs.rule_failed_note')])->save();

            return;
        }

        $wrong = [];
        foreach ((array) ($check->data['wrong'] ?? []) as $w) {
            if (is_array($w) && isset($changes[(string) ($w['id'] ?? '')])) {
                $wrong[(string) $w['id']] = mb_substr(trim((string) ($w['why'] ?? '')), 0, 200);
            }
        }
        $kept = array_values(array_diff_key($changes, $wrong));
        $why = mb_substr(trim((string) ($check->data['why'] ?? '')), 0, TaxonomyRule::MAX_TEXT);

        if ($kept === []) {
            $rule->forceFill(['status' => TaxonomyRule::UNCLEAR, 'note' => $why !== '' ? $why : __('taxonomy::runs.rule_unclear_note'), 'fingerprint' => $fingerprint, 'proposed_by' => self::who('rule_writer'), 'audited_by' => self::who('rule_checker')])->save();
            $run->output(['rule' => $ruleId, 'products' => count($forModel), 'changes' => 0, 'wrong' => count($wrong)])->summary('taxonomy::runs.rule_unclear');

            return;
        }

        $rule->forceFill([
            'status' => TaxonomyRule::PROPOSED,
            'note' => $understood !== '' ? $understood : null,
            'plan' => ['understood' => $understood, 'changes' => $kept, 'wrong' => $wrong],
            'fingerprint' => $fingerprint,
            'proposed_by' => self::who('rule_writer'),
            'audited_by' => self::who('rule_checker'),
            'run_id' => $run->runId,
        ])->save();
        $run->output(['rule' => $ruleId, 'products' => count($forModel), 'changes' => count($kept), 'wrong' => count($wrong)])
            ->summary('taxonomy::runs.rule_read', ['changes' => (string) count($kept)]);
    }

    /** A role's model, as the record names it. */
    private static function who(string $role): string
    {
        $who = Models::of($role);

        return $who->provider->value.' · '.$who->model;
    }

    /**
     * The products the rule may be about, by code: those whose title the rule names (every word of the title in the
     * rule, or the title in a quoted part of it), and those in a category or under a store tag it names, up to
     * taxonomy.rule_candidates_max. The named first.
     *
     * @return list<CatalogProduct>
     */
    private function candidates(string $text): array
    {
        $max = max(1, (int) Settings::get('taxonomy.rule_candidates_max'));
        $said = array_flip(Norm::words($text));
        $quoted = preg_match_all('/["“”„«»\'‘’]([^"“”„«»\'‘’]{2,120})["“”„«»\'‘’]/u', $text, $m) ? array_map(fn (string $q): array => Norm::words($q), $m[1]) : [];
        $quoted = array_values(array_filter($quoted, fn (array $q): bool => $q !== []));
        $products = CatalogProduct::query()->whereNull('removed_at')->with('categories:id,name')->orderBy('id')->get(['id', 'shop_id', 'external_id', 'title', 'image_url', 'url', 'payload']);
        $named = [];
        $scoped = [];

        foreach ($products as $product) {
            $words = Norm::words((string) $product->title);
            if ($words === []) {
                continue;
            }
            $whole = (count($words) >= 2 || mb_strlen($words[0]) >= 4) && self::allIn($words, $said);
            $inQuote = array_filter($quoted, fn (array $q): bool => self::allIn($q, array_flip($words)) || self::allIn($words, array_flip($q))) !== [];
            if ($whole || $inQuote) {
                $named[] = $product;

                continue;
            }
            // in a category, or under a store tag, the rule names
            $groups = [...$product->categories->pluck('name')->all(), ...self::storeTags($product)];
            foreach ($groups as $name) {
                $w = Norm::words((string) $name);
                if ($w !== [] && (count($w) >= 2 || mb_strlen($w[0]) >= 3) && self::allIn($w, $said)) {
                    $scoped[] = $product;
                    break;
                }
            }
        }

        return array_slice([...$named, ...$scoped], 0, $max);
    }

    /**
     * @param  list<string>  $words
     * @param  array<string, int>  $in
     */
    private static function allIn(array $words, array $in): bool
    {
        foreach ($words as $w) {
            if (! isset($in[$w])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function storeTags(CatalogProduct $product): array
    {
        return array_values(array_filter(array_map(fn ($t): string => trim(is_array($t) ? (string) ($t['name'] ?? '') : (string) $t), (array) ($product->payload['tags'] ?? [])), fn (string $t): bool => $t !== ''));
    }

    /**
     * The bank's active tags by "group:tag", each with its labels.
     *
     * @param  Collection<int, TaxonomyGroup>  $groups
     * @return array<string, array{tag: TaxonomyTag, label: string}>
     */
    private static function tagsByKey(Collection $groups): array
    {
        $tags = [];
        foreach ($groups as $group) {
            foreach ($group->tags as $tag) {
                $tags[$group->key.':'.$tag->key] = ['tag' => $tag, 'label' => (string) $tag->label_he];
            }
        }

        return $tags;
    }

    /**
     * The tags each product has now, as "group:tag".
     *
     * @param  list<string>  $productIds
     * @param  array<string, array{tag: TaxonomyTag, label: string}>  $tags
     * @return array<string, list<string>>
     */
    private static function currentTags(array $productIds, array $tags): array
    {
        $keyOfTag = [];
        foreach ($tags as $key => $t) {
            $keyOfTag[(string) $t['tag']->id] = $key;
        }
        $current = [];
        foreach (TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->whereIn('subject_id', $productIds)->where('status', TaxonomyAssignment::KEPT)->orderBy('id')->get(['subject_id', 'tag_id']) as $a) {
            if (isset($keyOfTag[(string) $a->tag_id])) {
                $current[(string) $a->subject_id][] = $keyOfTag[(string) $a->tag_id];
            }
        }

        return $current;
    }

    /**
     * Code keeps only what it gave: products among the candidates, tags of the bank (a tag taken off only where the
     * product has it), pairs among the candidates and never with itself. A product with nothing left is dropped.
     *
     * @param  array<string, CatalogProduct>  $byId  by external id
     * @param  array<string, array{tag: TaxonomyTag, label: string}>  $tags
     * @param  array<string, list<string>>  $current  by product id
     * @return array<string, array{external_id: string, title: string, image: string|null, url: string|null, add: list<string>, add_keys: list<string>, remove: list<string>, remove_keys: list<string>, apart: list<string>, apart_ids: list<string>, alike: list<string>, alike_ids: list<string>}> by external id
     */
    private static function gate(array $changes, array $byId, array $tags, array $current): array
    {
        $kept = [];

        foreach ($changes as $change) {
            $id = is_array($change) ? (string) ($change['id'] ?? '') : '';
            if (! isset($byId[$id]) || isset($kept[$id]) || count($kept) >= self::MAX_CHANGES) {
                continue;
            }
            $product = $byId[$id];
            $has = array_flip($current[(string) $product->id] ?? []);
            $keys = fn (mixed $list, callable $ok): array => array_values(array_unique(array_filter(array_map(fn ($k): string => trim((string) $k), (array) $list), fn (string $k): bool => $k !== '' && $ok($k))));
            $add = $keys($change['add'] ?? [], fn (string $k): bool => isset($tags[$k]) && ! isset($has[$k]));
            $remove = $keys($change['remove'] ?? [], fn (string $k): bool => isset($tags[$k]) && isset($has[$k]));
            $apart = $keys($change['apart'] ?? [], fn (string $k): bool => $k !== $id && isset($byId[$k]));
            $alike = $keys($change['alike'] ?? [], fn (string $k): bool => $k !== $id && isset($byId[$k]) && ! in_array($k, $apart, true));

            if ($add === [] && $remove === [] && $apart === [] && $alike === []) {
                continue;
            }

            $kept[$id] = [
                'external_id' => $id,
                'title' => (string) $product->title,
                'image' => $product->image_url,
                'url' => $product->url,
                'add' => array_map(fn (string $k): string => $tags[$k]['label'], $add),
                'add_keys' => $add,
                'remove' => array_map(fn (string $k): string => $tags[$k]['label'], $remove),
                'remove_keys' => $remove,
                'apart' => array_map(fn (string $k): string => (string) $byId[$k]->title, $apart),
                'apart_ids' => $apart,
                'alike' => array_map(fn (string $k): string => (string) $byId[$k]->title, $alike),
                'alike_ids' => $alike,
            ];
        }

        return $kept;
    }
}
