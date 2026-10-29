<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Catalog\Models\CatalogContent;
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

    public const WRITE_VERSION = 3;

    /** Products the rule names by title, whose links are read two by two, at most. */
    private const NAMED_LINKED = 6;

    /** @var array<string, true> the external ids of the products the rule names by title, of the last read */
    private array $named = [];

    public const CHECK_VERSION = 1;

    /** Changes a rule may make at most: a rule of a whole category stays readable on the screen. */
    private const MAX_CHANGES = 120;

    /** Whether the last read should be tried again later: the model was busy or out of reach, not the rule unclear. */
    public bool $later = false;

    /** A call that failed so: worth reading the rule again later. */
    private const LATER = [ModelCallFailed::RATE_LIMITED, ModelCallFailed::PROVIDER_ERROR];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
    ) {}

    /** @param  bool  $last  the last try: a busy model fails the rule instead of leaving it to be read again */
    public function handle(string $shopId, string $ruleId, bool $last = true): void
    {
        $this->later = false;
        $this->last = $last;
        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['rule' => $ruleId],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $ruleId)),
        );
    }

    private bool $last = true;

    /** A model call failed: the rule waits to be read again, unless this was its last try or the failure is not passing. */
    private function failed(TaxonomyRule $rule): void
    {
        if (! $this->last && in_array($this->models->failure, self::LATER, true)) {
            $this->later = true;

            return;
        }
        $rule->forceFill(['status' => TaxonomyRule::FAILED, 'note' => __('taxonomy::runs.rule_failed_note')])->save();
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
        $candidates = $this->candidates($rule->text, (array) $rule->mentions);
        // the posts and pages it names with "@": they take tags, never a place among the alike
        $contents = self::contents((array) $rule->mentions);

        if ($candidates === [] && $contents === []) {
            $rule->forceFill(['status' => TaxonomyRule::UNCLEAR, 'note' => __('taxonomy::runs.rule_nothing_named')])->save();
            $run->output(['rule' => $ruleId, 'products' => 0])->summary('taxonomy::runs.rule_unclear');

            return;
        }

        $byId = [];
        $forModel = [];
        $current = self::currentTags([...array_map(fn (CatalogProduct $p): string => (string) $p->id, $candidates), ...array_map(fn (CatalogContent $c): string => (string) $c->id, $contents)], $tags);

        foreach ($candidates as $product) {
            $byId[(string) $product->external_id] = $product;
            $forModel[] = [
                'id' => (string) $product->external_id,
                'title' => (string) $product->title,
                'named' => isset($this->named[(string) $product->external_id]),
                'categories' => $product->categories->pluck('name')->map(fn ($n): string => (string) $n)->values()->all(),
                'store_tags' => self::storeTags($product),
                'tags' => $current[(string) $product->id] ?? [],
            ];
        }
        foreach ($contents as $content) {
            $byId['c:'.$content->external_id] = $content;
            $forModel[] = [
                'id' => 'c:'.$content->external_id,
                'title' => (string) $content->title,
                'kind' => (string) $content->type,
                'named' => true,
                'categories' => [],
                'store_tags' => [],
                'tags' => $current[(string) $content->id] ?? [],
            ];
        }
        // what ties each two products the rule names now: the tags, categories and store tags they share (Aviad, 29.10:
        // find them, read what links them, and propose what keeps them apart)
        $links = self::links(array_values(array_filter($forModel, fn (array $p): bool => $p['named'] && ! isset($p['kind']))), $tags);

        // the same rule against the same products, with the same tags, is read once
        $fingerprint = hash('sha256', json_encode([self::WRITE_VERSION, self::CHECK_VERSION, trim($rule->text), array_map(fn (array $p): array => [$p['id'], $p['tags']], $forModel)], JSON_UNESCAPED_UNICODE));
        $before = TaxonomyRule::query()->where('fingerprint', $fingerprint)->whereKeyNot($rule->id)->whereNotNull('plan')->latest('id')->first();

        if ($before !== null) {
            $rule->forceFill(['status' => TaxonomyRule::PROPOSED, 'note' => $before->note, 'plan' => $before->plan, 'fingerprint' => $fingerprint, 'proposed_by' => $before->proposed_by, 'audited_by' => $before->audited_by])->save();
            $run->output(['rule' => $ruleId, 'products' => count($forModel), 'read_before' => $before->id])->summary('taxonomy::runs.rule_read_again');

            return;
        }

        $reply = $this->models->call($run, 'read_rule', self::WRITE_VERSION, 'rule_writer', ['rule' => $rule->text, 'products' => $forModel, 'links' => $links], Bank::prefix($groups));

        if ($reply === null) {
            $this->failed($rule);

            return;
        }

        $understood = mb_substr(trim((string) ($reply->data['understood'] ?? '')), 0, TaxonomyRule::MAX_TEXT);
        $link = mb_substr(trim((string) ($reply->data['link'] ?? '')), 0, TaxonomyRule::MAX_TEXT);
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
            $this->failed($rule);

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
            'plan' => ['understood' => $understood, 'link' => $link, 'links' => $links, 'changes' => $kept, 'wrong' => $wrong],
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
    /**
     * The posts and pages a rule names with "@", as the store has them now.
     *
     * @param  list<array<string, mixed>>  $mentions
     * @return list<CatalogContent>
     */
    private static function contents(array $mentions): array
    {
        $ids = array_values(array_map(fn (array $m): string => (string) $m['id'], array_filter($mentions, fn ($m): bool => is_array($m) && in_array($m['type'] ?? '', ['post', 'page'], true))));

        return $ids === [] ? [] : CatalogContent::query()->whereNull('removed_at')->whereIn('external_id', $ids)->orderBy('id')->get(['id', 'type', 'external_id', 'title', 'url', 'image_url'])->all();
    }

    /** @param  list<array<string, mixed>>  $mentions  what the rule names with "@": named exactly, before any by its words */
    private function candidates(string $text, array $mentions = []): array
    {
        $max = max(1, (int) Settings::get('taxonomy.rule_candidates_max'));
        $of = fn (string $type): array => array_flip(array_map(fn (array $m): string => (string) $m['id'], array_filter($mentions, fn ($m): bool => is_array($m) && ($m['type'] ?? '') === $type)));
        $atProducts = $of('product');
        $atCategories = $of('category');
        // the words the rule says, without the names it gave with "@": a category named so is not also read as words
        foreach ($mentions as $m) {
            $text = str_replace('@'.(string) ($m['label'] ?? ''), ' ', $text);
        }
        $said = array_flip(Norm::words($text));
        $quoted = preg_match_all('/["“”„«»\'‘’]([^"“”„«»\'‘’]{2,120})["“”„«»\'‘’]/u', $text, $m) ? array_map(fn (string $q): array => Norm::words($q), $m[1]) : [];
        $quoted = array_values(array_filter($quoted, fn (array $q): bool => $q !== []));
        $products = CatalogProduct::query()->whereNull('removed_at')->with('categories:id,name,external_id')->orderBy('id')->get(['id', 'shop_id', 'external_id', 'title', 'image_url', 'url', 'payload']);
        $atNamed = [];
        $atScoped = [];
        $named = [];
        $this->named = [];
        $scoped = [];

        foreach ($products as $product) {
            if (isset($atProducts[(string) $product->external_id])) {
                $atNamed[] = $product;

                continue;
            }
            if ($atCategories !== [] && $product->categories->contains(fn ($c): bool => isset($atCategories[(string) $c->external_id]))) {
                $atScoped[] = $product;

                continue;
            }
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

        $named = [...$atNamed, ...$named];
        foreach ($named as $product) {
            $this->named[(string) $product->external_id] = true;
        }

        return array_slice([...$named, ...$atScoped, ...$scoped], 0, $max);
    }

    /**
     * What each two named products share now, by its name: the bank's tags (their labels), the store's categories and
     * its own tags. Only pairs that share something.
     *
     * @param  list<array{id: string, title: string, categories: list<string>, store_tags: list<string>, tags: list<string>}>  $named
     * @param  array<string, array{tag: TaxonomyTag, label: string}>  $tags
     * @return list<array{a: string, b: string, shared: list<string>}>
     */
    private static function links(array $named, array $tags): array
    {
        $named = array_slice($named, 0, self::NAMED_LINKED);
        $of = fn (array $p): array => array_values(array_unique([
            ...array_map(fn (string $k): string => $tags[$k]['label'] ?? $k, $p['tags']),
            ...$p['categories'],
            ...$p['store_tags'],
        ]));
        $links = [];
        foreach ($named as $i => $a) {
            foreach (array_slice($named, $i + 1) as $b) {
                $shared = array_values(array_intersect($of($a), $of($b)));
                if ($shared !== []) {
                    $links[] = ['a' => $a['id'], 'b' => $b['id'], 'shared' => $shared];
                }
            }
        }

        return $links;
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
        foreach (TaxonomyAssignment::query()->whereIn('subject_type', [TaxonomyAssignment::PRODUCT, TaxonomyAssignment::CONTENT])->whereIn('subject_id', $productIds)->where('status', TaxonomyAssignment::KEPT)->orderBy('id')->get(['subject_id', 'tag_id']) as $a) {
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
            $isProduct = fn (string $k): bool => ! str_starts_with($k, 'c:');
            $apart = $isProduct($id) ? $keys($change['apart'] ?? [], fn (string $k): bool => $k !== $id && isset($byId[$k]) && $isProduct($k)) : [];
            $alike = $isProduct($id) ? $keys($change['alike'] ?? [], fn (string $k): bool => $k !== $id && isset($byId[$k]) && $isProduct($k) && ! in_array($k, $apart, true)) : [];

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
