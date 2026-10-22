<?php

namespace App\Modules\Thinking\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Assistant\Contracts\ProductSets;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Thinking\Models\ProductSet;
use App\Modules\Thinking\Support\SetRequest;
use App\Modules\Thinking\Support\Words;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A question that asks to see a whole set of the store's products, answered with all of them, in
 * any store: the words are read against the store's own categories, brands, tags and fields.
 *
 * 1. Code reads what the question asks to see (SetRequest), and leaves out the words most of the
 *    catalogue holds ("ספרי" in a bookshop, "טבעת" in a ring shop): what is left sets products apart.
 * 2. A category or brand of the store whose name holds all of that is the set, read by code ("תציג
 *    לי את ספריית שיבולת"); or two whose names hold it together, the products in both ("ספריית
 *    שיבולת ביוגרפיות"). A set larger than thinking.focus_over offers the shopper the store's other
 *    categories within it to narrow it by. No model.
 * 3. Otherwise (thinking.sets_by_meaning) code gathers the products to choose from: in a store of
 *    up to thinking.whole_catalog_max products, all of them; in a larger one, those whose title,
 *    groups, fields or description hold those words in any of their forms ("החטופים", "נחטף"), and
 *    those nearest in meaning, at most thinking.max_candidates. Each carries the store's fields and
 *    what the scan learned of it and kept (a stone's colour read from its picture, a book's topic).
 * 4. The same words with the same products to choose from were chosen before: that, no model.
 * 5. One family chooses what the question asks for and names it; when nothing is, the nearest the
 *    store has, named as what they are ("ספרות אימה" for one who asked for science fiction, never
 *    called science fiction); when too much fits to choose well, one question to narrow it by. A
 *    model of the other family takes out what does not hold up, and the question if it does not
 *    help. Code keeps only ids it gave. The set is kept, an empty one too, so the same question
 *    asks no model again until the products change.
 *
 * Every call asks SpendGuard first and is recorded on one run with its tokens and cost.
 */
final class FindProductSet implements ProductSets
{
    public const AGENT = 'thinking.set_finder';

    public const ACTION = 'thinking.find_set';

    /** find_set and check_set */
    public const PROMPT_VERSION = 3;

    /** Products a word is looked for in, at most, before code reads them. */
    private const LOOKED_AT = 400;

    private const DESCRIPTION_SCORE = 1;

    private const GROUP_SCORE = 2;

    private const TITLE_SCORE = 3;

    /** A category offered to narrow a set by holds between these shares of it. */
    private const FOCUS_MIN_SHARE = 0.1;

    private const FOCUS_MAX_SHARE = 0.85;

    private const MAX_OPTIONS = 4;

    private const MAX_NEAR = 6;

    /** Fields a candidate carries to the model, at most: the store's, then what the scan learned. */
    private const MAX_FIELDS = 14;

    /** The kinds of fact the scan learned that say what a product is or has. */
    private const LEARNED = [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Use, FactKind::Tag, FactKind::ShopperValue, FactKind::Category];

    /** @var array<string, array<string, list<string>>> product id => key => values the scan learned (learn()) */
    private array $facts = [];

    /** @var array<string, true> external ids of the candidates whose own words hold the subject's (candidates()) */
    private array $says = [];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function find(string $shopId, string $question, string $locale, callable $mayAsk): ?array
    {
        $subject = SetRequest::subject($question);

        if ($subject === null || ! Features::enabled('thinking.sets', $shopId)) {
            return null;
        }

        // a word most of the catalogue holds may be left unsaid by a group's name ("ספרי" of "ספרי
        // החטופים"); the others must be said. All of them general ("ספריית שיבולת" in a store that is
        // mostly that library): all must be.
        $words = array_values(array_filter(Words::of($subject), fn (string $w): bool => mb_strlen($w) >= 2));
        $meant = array_values(array_filter($words, fn (string $w): bool => ! $this->generic($w))) ?: $words;

        if ($meant === []) {
            return null;
        }

        $found = $this->group($shopId, $subject, $meant, $locale)
            ?? (Features::enabled('thinking.sets_by_meaning', $shopId) ? $this->byMeaning($shopId, $question, $subject, $meant, $locale, $mayAsk) : null);

        // the store's search for more only for a group the store keeps, by its name: for a set chosen by
        // meaning the whole store was already looked through, and the search's words would bring what
        // only shares them ("ספר מדע בדיוני" in a store with none: every book of science)
        return $found === null ? null : $found + ['search' => $found['from'] === 'code' && ! str_contains($found['title'], ' · ') ? $found['title'] : null];
    }

    /** A word most of the catalogue holds, in its titles or its categories' names. */
    private function generic(string $word): bool
    {
        $total = CatalogProduct::query()->whereNull('removed_at')->count();
        $core = Words::core($word);

        if ($total === 0 || mb_strlen($core) < 3) {
            return true;
        }

        $holding = CatalogProduct::query()->whereNull('removed_at')
            ->where(fn (Builder $q) => $q->where('title', 'like', '%'.$core.'%')
                ->orWhereHas('categories', fn (Builder $c) => $c->where('name', 'like', '%'.$core.'%')))
            ->count();

        return $holding / $total * 100 >= (int) Settings::get('thinking.generic_percent');
    }

    /**
     * 2: the store's category or brand whose name holds every word that sets the products apart, the
     * one whose name says least besides; or two that hold them together, the products in both.
     *
     * @param  list<string>  $meant
     * @return array<string, mixed>|null
     */
    private function group(string $shopId, string $subject, array $meant, string $locale): ?array
    {
        $subjectText = Words::searchable($subject);
        $groups = [];

        $consider = function (string $name, ?string $url, Builder $products, ?string $categoryId) use (&$groups, $meant, $subjectText): void {
            $text = Words::searchable($name);
            $covers = array_values(array_filter($meant, fn (string $w): bool => Words::has($text, Words::stems($w))));
            if ($covers === []) {
                return;
            }
            $key = implode(' ', Words::of($name));
            $ids = $this->ordered($products);
            // a category and a brand of the same name (a publisher's books) are one group
            $groups[$key] = [
                'name' => $name,
                'url' => $groups[$key]['url'] ?? $url,
                'covers' => $covers,
                'extra' => count(array_filter(Words::of($name), fn (string $w): bool => mb_strlen($w) >= 2 && ! Words::has($subjectText, Words::stems($w)))),
                'ids' => array_values(array_unique([...($groups[$key]['ids'] ?? []), ...$ids])),
                'category' => $groups[$key]['category'] ?? $categoryId,
            ];
        };

        foreach (CatalogCategory::query()->whereNull('removed_at')->where('product_count', '>', 0)->get(['id', 'name', 'url']) as $category) {
            $consider($category->name, $category->url, CatalogProduct::query()->whereNull('removed_at')->whereHas('categories', fn (Builder $c) => $c->whereKey($category->id)), $category->id);
        }

        foreach (CatalogProduct::query()->whereNull('removed_at')->whereNotNull('brand')->distinct()->pluck('brand') as $brand) {
            $consider((string) $brand, null, CatalogProduct::query()->whereNull('removed_at')->where('brand', $brand), null);
        }

        $groups = array_values(array_filter($groups, fn (array $g): bool => $g['ids'] !== []));
        $whole = array_values(array_filter($groups, fn (array $g): bool => count($g['covers']) === count($meant)));
        usort($whole, fn (array $a, array $b): int => $a['extra'] <=> $b['extra']);

        if ($whole !== []) {
            // two that fit alike: none, and meaning decides
            if (isset($whole[1]) && $whole[1]['extra'] === $whole[0]['extra']) {
                return null;
            }
            $chosen = [$whole[0]];
            $ids = $whole[0]['ids'];
        } else {
            [$chosen, $ids] = $this->pair($groups, $meant);
            if ($chosen === []) {
                return null;
            }
        }

        $name = implode(' · ', array_column($chosen, 'name'));
        $max = (int) Settings::get('thinking.max_set', $shopId);

        return [
            'answer' => (string) __(count($ids) === 1 ? 'thinking::sets.group_one' : 'thinking::sets.group', ['count' => count($ids), 'name' => $name], $locale),
            'ids' => array_slice($ids, 0, $max),
            'title' => $name,
            'from' => 'code',
            'near' => false,
            'page' => count($chosen) === 1 && $chosen[0]['url'] ? ['title' => $chosen[0]['name'], 'url' => $chosen[0]['url']] : null,
            'ask' => $this->focus($shopId, $ids, array_filter(array_column($chosen, 'category')), $locale),
        ];
    }

    /**
     * Two groups whose names hold the words together, with products in both: the pair saying least
     * besides, then the one with most products in both.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  list<string>  $meant
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function pair(array $groups, array $meant): array
    {
        $best = [[], []];
        $bestRank = null;

        foreach ($groups as $i => $a) {
            foreach (array_slice($groups, $i + 1) as $b) {
                if (count(array_unique([...$a['covers'], ...$b['covers']])) !== count($meant)) {
                    continue;
                }
                $both = array_values(array_intersect($a['ids'], $b['ids']));
                $rank = [$a['extra'] + $b['extra'], -count($both)];
                if ($both !== [] && ($bestRank === null || $rank < $bestRank)) {
                    [$best, $bestRank] = [[[$a, $b], $both], $rank];
                }
            }
        }

        return $best;
    }

    /**
     * A set too large to look through (over thinking.focus_over): the store's other categories within
     * it, each holding between a tenth and most of it, to narrow it by. Code only.
     *
     * @param  list<string>  $ids
     * @param  array<int, string>  $within  the categories the set is already
     * @return array{question: string, options: list<string>}|null
     */
    private function focus(string $shopId, array $ids, array $within, string $locale): ?array
    {
        if (count($ids) <= (int) Settings::get('thinking.focus_over', $shopId)) {
            return null;
        }

        $counts = [];
        CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $ids)->with('categories:id,name')->get(['id', 'external_id'])
            ->each(function (CatalogProduct $p) use (&$counts, $within): void {
                foreach ($p->categories as $category) {
                    if (! in_array($category->id, $within, true)) {
                        $counts[$category->name] = ($counts[$category->name] ?? 0) + 1;
                    }
                }
            });

        $options = array_filter($counts, fn (int $n): bool => $n >= count($ids) * self::FOCUS_MIN_SHARE && $n <= count($ids) * self::FOCUS_MAX_SHARE);
        arsort($options);
        $options = array_slice(array_map('strval', array_keys($options)), 0, self::MAX_OPTIONS);

        return count($options) < 2 ? null : ['question' => (string) __('thinking::sets.focus', [], $locale), 'options' => $options];
    }

    /** @return list<string> external ids, in stock first, then as the store lists them */
    private function ordered(Builder $query): array
    {
        return $query->orderByDesc('in_stock')->orderBy('id')->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * 3 to 5: the set only meaning holds.
     *
     * @param  list<string>  $meant
     * @param  callable(): bool  $mayAsk
     * @return array<string, mixed>|null
     */
    private function byMeaning(string $shopId, string $question, string $subject, array $meant, string $locale, callable $mayAsk): ?array
    {
        $whole = CatalogProduct::query()->whereNull('removed_at')->count() <= (int) Settings::get('thinking.whole_catalog_max', $shopId);
        $candidates = $this->candidates($shopId, $subject, $meant, $whole);

        if ($candidates->isEmpty()) {
            return null;
        }

        $key = SetRequest::key($subject);
        $ids = $candidates->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
        $hashes = $candidates->mapWithKeys(fn (CatalogProduct $p): array => [(string) $p->external_id => $p->hash])->all();
        ksort($hashes, SORT_STRING);
        // and what the scan learned of them: a set chosen before the books had a genre is chosen again once they do.
        // Sorted, so the same tags read again at night give the same fingerprint.
        $learned = [];
        foreach ($candidates as $product) {
            $tags = [];
            foreach ($this->facts[$product->id] ?? [] as $field => $values) {
                foreach ($values as $value) {
                    $tags[] = $field.':'.$value;
                }
            }
            if ($tags !== []) {
                sort($tags, SORT_STRING);
                $learned[(string) $product->external_id] = array_values(array_unique($tags));
            }
        }
        ksort($learned, SORT_STRING);
        // what the set was chosen from and by: the products, what is known of them, the prompts' version and the two models
        $models = [Settings::get('thinking.picker_model'), Settings::get('thinking.checker_model')];
        $fingerprint = hash('sha256', (string) json_encode([self::PROMPT_VERSION, $models, $key, $hashes, $learned]));
        $saved = ProductSet::query()->where('subject_key', $key)->first();
        $from = 'bank';

        if ($saved === null || $saved->fingerprint !== $fingerprint) {
            if (! $mayAsk()) {
                return null;
            }
            $saved = $this->choose($shopId, $question, $subject, $key, $fingerprint, $candidates, $whole);
            $from = 'model';
        }

        if ($saved === null) {
            return null;
        }

        $matches = array_values(array_intersect((array) $saved->product_ids, $ids));
        $near = array_values(array_intersect((array) $saved->near_ids, $ids));
        $ask = $matches !== [] ? $saved->ask : null;

        if ($matches !== []) {
            $title = trim((string) $saved->title) !== '' ? (string) $saved->title : $subject;
            $answer = __(count($matches) === 1 ? 'thinking::sets.meaning_one' : 'thinking::sets.meaning', ['count' => count($matches), 'title' => $title], $locale);
        } elseif ($near !== []) {
            $title = trim((string) $saved->near_title) !== '' ? (string) $saved->near_title : $subject;
            $answer = __('thinking::sets.near', ['subject' => $subject, 'title' => $title], $locale);
        } else {
            // looked through and none is what was asked, nor close: said so, with no products
            $title = $subject;
            $answer = __('thinking::sets.none', ['subject' => $subject], $locale);
        }

        return [
            'answer' => (string) $answer,
            'ids' => $matches !== [] ? $matches : $near,
            'title' => $title,
            'from' => $from,
            'near' => $matches === [],
            'page' => null,
            'ask' => $ask,
        ];
    }

    /**
     * The products to choose from. In a small store all of them, those the words name first. In a
     * larger one, those whose title, groups, fields or description hold the words in any of their
     * forms, best first, then those nearest the subject in meaning. Code only (the meaning costs one
     * small embedding the first time a wording is seen).
     *
     * @param  list<string>  $meant
     * @return Collection<int, CatalogProduct>
     */
    private function candidates(string $shopId, string $subject, array $meant, bool $whole): Collection
    {
        $query = CatalogProduct::query()->whereNull('removed_at')->with('categories:id,name');

        if (! $whole) {
            $likes = array_merge(...array_map(Words::likes(...), $meant));
            $query->where(function (Builder $q) use ($likes): void {
                foreach ($likes as $like) {
                    $q->orWhere('title', 'like', '%'.$like.'%')
                        ->orWhere('brand', 'like', '%'.$like.'%')
                        ->orWhere('payload->description', 'like', '%'.$like.'%')
                        ->orWhere('payload->short_description', 'like', '%'.$like.'%')
                        ->orWhereHas('categories', fn (Builder $c) => $c->where('name', 'like', '%'.$like.'%'));
                }
            })->limit(self::LOOKED_AT);
        }

        $found = $query->orderBy('id')->get()->keyBy('external_id');
        $this->learn($found);
        $this->says = [];
        $scores = [];

        foreach ($found as $id => $product) {
            $title = Words::searchable($product->title);
            $groups = Words::searchable(implode(' ', [...$this->groups($product), ...$this->fields($product)]));
            $text = Words::searchable($this->text($product));
            $scores[$id] = 0;
            foreach ($meant as $word) {
                $stems = Words::stems($word);
                $scores[$id] += Words::has($title, $stems) ? self::TITLE_SCORE : (Words::has($groups, $stems) ? self::GROUP_SCORE : (Words::has($text, $stems) ? self::DESCRIPTION_SCORE : 0));
            }
            if ($scores[$id] > 0) {
                $this->says[(string) $id] = true;
            }
        }

        if ($whole) {
            arsort($scores);

            return collect(array_keys($scores))->map(fn ($id): CatalogProduct => $found->get($id))->values();
        }

        $scores = array_filter($scores);
        $max = (int) Settings::get('thinking.max_candidates', $shopId);

        // nearest in meaning, after those the words name, while there is room
        if (count($scores) < $max && app()->bound(SemanticSearch::class)) {
            foreach (app(SemanticSearch::class)->nearText($shopId, $subject, ['product'], $max) as $i => $hit) {
                $id = (string) ($hit['external_id'] ?? '');
                if ($id !== '' && ! isset($scores[$id])) {
                    $scores[$id] = 0.5 / ($i + 1);
                }
            }
        }

        arsort($scores);
        $keep = array_slice(array_map('strval', array_keys($scores)), 0, $max);
        $missing = array_values(array_diff($keep, $found->keys()->map(fn ($k): string => (string) $k)->all()));
        $more = $missing === [] ? collect() : CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $missing)->with('categories:id,name')->get()->keyBy('external_id');

        $candidates = collect($keep)->map(fn (string $id): ?CatalogProduct => $found->get($id) ?? $more->get($id))->filter()->values();
        $this->learn($candidates);

        return $candidates;
    }

    /**
     * 5: one family chooses, the other takes out what does not hold up, and the set is kept.
     *
     * @param  Collection<int, CatalogProduct>  $candidates
     */
    private function choose(string $shopId, string $question, string $subject, string $key, string $fingerprint, Collection $candidates, bool $whole): ?ProductSet
    {
        $saved = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: ['question' => mb_substr($question, 0, 300), 'subject' => $subject, 'candidates' => $candidates->count(), 'whole_catalog' => $whole],
            work: function (RunContext $run) use ($question, $subject, $key, $fingerprint, $candidates, $whole, &$saved): void {
                $picker = AgentModel::from('thinking.picker_provider', 'thinking.picker_model');
                $checker = AgentModel::from('thinking.checker_provider', 'thinking.checker_model');

                if ($picker->provider->family() === $checker->provider->family()) {
                    $run->fail('thinking::runs.same_family', ['family' => $picker->provider->label()]);

                    return;
                }

                $chars = (int) Settings::get('thinking.candidate_chars');
                $byRef = [];
                $items = [];
                $full = [];
                foreach ($candidates->values() as $i => $product) {
                    $byRef[$ref = 'c'.($i + 1)] = $product;
                    // in a whole store, only those whose words hold the subject carry their description in
                    // full; the rest its opening (thinking.whole_catalog_chars), so the model reads the store
                    // by its titles, groups and fields, not a wall of text
                    $says = isset($this->says[(string) $product->external_id]);
                    $full[$ref] = $this->excerpt($product, $subject, $chars);
                    $items[$ref] = ['id' => $ref, 'title' => $product->title, 'groups' => $this->groups($product), 'attributes' => $this->fields($product), 'says' => $says,
                        'text' => $says || ! $whole ? $full[$ref] : mb_substr($full[$ref], 0, (int) Settings::get('thinking.whole_catalog_chars'))];
                }

                $picked = $this->call($run, 'find_set', 'picker', $picker, ['request' => $question, 'subject' => $subject, 'candidates' => array_values($items)]);
                if ($picked === null) {
                    return;
                }

                $refs = fn (string $field, int $max): array => array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($picked->data[$field] ?? [])), fn (string $r): bool => isset($byRef[$r])))), 0, $max);
                $matches = $refs('matches', count($byRef));
                $near = $matches === [] ? $refs('near', self::MAX_NEAR) : [];
                $picks = count($matches) + count($near);
                $title = self::line($picked->data['title'] ?? '', 80);
                $nearTitle = self::line($picked->data['near_title'] ?? '', 80);
                $ask = $matches === [] ? null : self::ask((array) ($picked->data['ask'] ?? []));
                $run->summary('thinking::runs.picked', ['count' => (string) count($matches), 'near' => (string) count($near), 'of' => (string) count($byRef), 'subject' => $subject]);

                if ($matches !== [] || $near !== []) {
                    $chosen = [];
                    foreach ([...array_fill_keys($matches, 'match'), ...array_fill_keys($near, 'near')] as $ref => $as) {
                        // the checker reads each chosen product's description whole, whatever the picker was shown
                        $chosen[] = ['text' => $full[$ref], 'as' => $as] + array_diff_key($items[$ref], ['says' => true]);
                    }
                    $checked = $this->call($run, 'check_set', 'checker', $checker, ['request' => $question, 'subject' => $subject, 'chosen' => $chosen, 'near_title' => $nearTitle, 'ask' => $ask ?? ['question' => '', 'options' => []]]);
                    if ($checked === null) {
                        return;
                    }
                    // each taken out with why, for the run: what the operator reads when a product is missed
                    $why = [];
                    foreach ((array) ($checked->data['wrong'] ?? []) as $item) {
                        $ref = (string) (is_array($item) ? ($item['id'] ?? '') : $item);
                        if (isset($byRef[$ref])) {
                            $why[$ref] = $byRef[$ref]->title.' — '.self::line(is_array($item) ? ($item['why'] ?? '') : '', 120);
                        }
                    }
                    $wrong = array_keys($why);
                    $matches = array_values(array_diff($matches, $wrong));
                    $near = array_values(array_diff($near, $wrong));
                    $ask = ($checked->data['ask_ok'] ?? false) === true && $matches !== [] ? $ask : null;
                    $run->summary('thinking::runs.checked', ['dropped' => (string) count($wrong), 'kept' => (string) (count($matches) + count($near))]);
                }

                $external = fn (array $list): array => array_map(fn (string $r): string => (string) $byRef[$r]->external_id, $list);
                $saved = ProductSet::query()->updateOrCreate(['subject_key' => $key], [
                    'subject' => mb_substr($subject, 0, 300),
                    'fingerprint' => $fingerprint,
                    'title' => $title === '' ? null : $title,
                    'product_ids' => $external($matches),
                    'near_ids' => $external($near),
                    'near_title' => $nearTitle === '' ? null : $nearTitle,
                    'ask' => $ask,
                    'candidate_ids' => $external(array_keys($byRef)),
                    'chosen_by' => $picker->provider->value.' · '.$picker->model.' / '.$checker->provider->value.' · '.$checker->model,
                    'run_id' => $run->runId,
                ]);
                $run->output(['subject' => $subject, 'candidates' => count($byRef), 'picked' => $picks, 'chosen' => count($matches), 'near' => count($near), 'asks' => $ask !== null, 'title' => $title, 'taken_out' => array_values($why ?? [])]);
            },
        );

        return $saved;
    }

    /** A short line a model wrote, as the shopper may read it: one line, no markup, at most $max characters. */
    private static function line(mixed $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_string($text) ? $text : ''))), 0, $max);
    }

    /**
     * The picker's question to narrow a broad request, kept when it has two to four short options.
     *
     * @param  array<string, mixed>  $ask
     * @return array{question: string, options: list<string>}|null
     */
    private static function ask(array $ask): ?array
    {
        $question = self::line($ask['question'] ?? '', 160);
        $options = array_slice(array_values(array_unique(array_filter(array_map(fn ($o): string => self::line($o, 40), (array) ($ask['options'] ?? []))))), 0, self::MAX_OPTIONS);

        return $question === '' || count($options) < 2 ? null : ['question' => $question, 'options' => $options];
    }

    /** @param  array<string, mixed>  $input */
    private function call(RunContext $run, string $prompt, string $role, AgentModel $who, array $input): ?ModelReply
    {
        $system = (string) file_get_contents($file = __DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md');
        $schema = json_decode((string) file_get_contents(substr($file, 0, -3).'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        $maxOutput = (int) Settings::get('thinking.max_output_tokens');
        $in = (float) Settings::get("thinking.{$role}_input_usd_per_million");
        $out = (float) Settings::get("thinking.{$role}_output_usd_per_million");

        try {
            $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
            $reply = is_array($schema) && $this->models instanceof StructuredChatModel
                ? $this->models->structured($who->provider, $who->model, $system, $user, $schema, $maxOutput)
                : $this->models->json($who->provider, $who->model, $system, $user, $maxOutput);
            $run->usage($who->provider->value, $who->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

            return $reply;
        } catch (SpendCapReached $e) {
            $run->fail('thinking::runs.spend_cap', [], $e->getMessage());
        } catch (ModelCallFailed $e) {
            $run->fail('thinking::runs.model_failed', ['reason' => $e->reason], $e->getMessage());
        }

        return null;
    }

    /** @return list<string> the store's categories, brand and tags for a product */
    private function groups(CatalogProduct $product): array
    {
        $tags = array_map(fn ($t): string => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t, (array) ($product->payload['tags'] ?? []));

        return array_values(array_unique(array_filter([...$product->categories->pluck('name')->all(), (string) $product->brand, ...$tags])));
    }

    /**
     * The store's fields for a product, "name: value, value", then what the scan learned of it and
     * kept (approved facts: a stone's colour read from its picture, a book's topic, a tool's use).
     *
     * @return list<string>
     */
    private function fields(CatalogProduct $product): array
    {
        $store = array_map(fn (array $a): string => $a['name'].': '.implode(', ', array_slice($a['values'], 0, 8)), array_slice($product->storeAttributes(), 0, 8));
        $learned = array_map(fn (string $key, array $values): string => $key.': '.implode(', ', array_slice(array_unique($values), 0, 6)), array_keys($this->facts[$product->id] ?? []), $this->facts[$product->id] ?? []);

        return array_slice([...$store, ...$learned], 0, self::MAX_FIELDS);
    }

    /**
     * What the scan learned of these products and kept, by product and key: the kinds that say what a
     * product is or has, not its highlights, summary or promises.
     *
     * @param  Collection<int, CatalogProduct>  $products
     */
    private function learn(Collection $products): void
    {
        $this->facts = [];

        foreach ($products->pluck('id')->chunk(500) as $ids) {
            EnrichmentFact::query()->whereIn('product_id', $ids->all())->where('status', FactStatus::Approved)
                ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, self::LEARNED))
                ->orderBy('key')->orderBy('id')->get(['product_id', 'key', 'value_text', 'value_number', 'unit'])
                ->each(function (EnrichmentFact $fact): void {
                    $value = $fact->value_text ?? ($fact->value_number === null ? null : rtrim(rtrim((string) $fact->value_number, '0'), '.').($fact->unit ? ' '.$fact->unit : ''));
                    if ($value !== null && $value !== '' && ! in_array($value, ['none', 'unknown'], true)) {
                        $this->facts[(string) $fact->product_id][$fact->key][] = $value;
                    }
                });
        }
    }

    private function text(CatalogProduct $product): string
    {
        $text = (string) ($product->payload['short_description'] ?? '').' '.(string) ($product->payload['description'] ?? '');

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $text)))));
    }

    /** What a candidate's description says, the sentences that hold the subject's words first, at most $limit characters. */
    private function excerpt(CatalogProduct $product, string $subject, int $limit): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $this->text($product)) ?: [];
        $stems = array_merge(...array_map(Words::stems(...), Words::of($subject)));
        usort($sentences, fn (string $a, string $b): int => (int) Words::has(Words::searchable($b), $stems) <=> (int) Words::has(Words::searchable($a), $stems));

        return mb_substr(implode(' ', $sentences), 0, $limit);
    }
}
