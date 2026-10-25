<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Localization\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Contracts\TriesQuestions;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\SearchTrace;
use Illuminate\Support\Facades\Cache;

/**
 * The search check screen's reading of one search, or one question to the store's AI: what code read from the
 * words, why each result is there (its name, a category, a tag, the meaning, the picture…), what was found and left
 * out, and what the store could change so the next shopper finds better. The same for every store: code reads the
 * search's own trace (SearchCatalog::explain()), never a rule for one store or one word.
 *
 * A search costs nothing more than a shopper's. A question goes the whole way a shopper's does (TriesQuestions),
 * nothing of it kept, and is held to search.check_questions_per_day.
 */
final class ExplainSearch
{
    /** Results explained: the drawer's first screen. */
    public const SHOWN = 12;

    /** Found and left out, the first this many. */
    private const LEFT_SHOWN = 10;

    /** Results only the meaning or the picture found, suggested to tag, at most this many. */
    private const MEANING_ONLY_SHOWN = 3;

    private const FINALS = ['כ' => 'ך', 'מ' => 'ם', 'נ' => 'ן', 'פ' => 'ף', 'צ' => 'ץ'];

    public function __construct(
        private readonly SearchCatalog $search,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, mixed> query, read, results, left, steps, suggestions, counts, asked_with */
    public function search(string $shopId, string $query): array
    {
        ['result' => $result, 'trace' => $trace] = $this->search->explain($shopId, $query, self::SHOWN);

        return $this->tenant->run($shopId, fn (): array => $this->read($shopId, $query, $result, $trace));
    }

    /**
     * The question asked of the store's AI as the drawer asks it after a search: with the products the search showed.
     *
     * @return array<string, mixed> search and answer; or limit with the day's cap when it is used up
     */
    public function question(string $shopId, string $question, string $locale): array
    {
        $cap = (int) Settings::get('search.check_questions_per_day', $shopId);
        $key = 'search:check-questions:'.$shopId.':'.now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        if ((int) Cache::get($key, 0) >= $cap) {
            return ['limit' => true, 'cap' => $cap];
        }

        Cache::increment($key);
        $search = $this->search($shopId, $question);
        $tried = app(TriesQuestions::class)->trial($shopId, $question, $search['asked_with'], $locale);

        return ['search' => $search, 'answer' => $this->answer($tried)];
    }

    /**
     * @param  array<string, mixed>  $result  SearchCatalog::handle()'s
     * @return array<string, mixed>
     */
    private function read(string $shopId, string $typed, array $result, SearchTrace $trace): array
    {
        $records = (array) (LoadedIndex::for($shopId)['records'] ?? []);
        $shown = (array) ($result['groups']['product'] ?? []);
        $products = CatalogProduct::query()->whereIn('external_id', array_column($shown, 'external_id'))->with('categories:id,name')->get()->keyBy('external_id');
        $words = $this->words($typed, (string) ($result['query'] ?? ''));
        $single = $result['single']['id'] ?? null;

        $results = [];
        foreach ($shown as $r) {
            $record = (array) ($records[$r['id']] ?? []);
            $product = $products[$r['external_id']] ?? null;
            $results[] = [
                'title' => (string) $r['title'],
                'url' => $r['url'] ?? null,
                'image' => $r['image'] ?? null,
                'price' => isset($record['pr']) ? Money::price((float) $record['pr'], (string) ($product?->currency ?: 'ILS')) : null,
                'in_stock' => ($record['s'] ?? 0) === 1,
                'ways' => $this->ways($trace->found[$r['id']] ?? [], $product, $record, $words, $single === $r['id']),
            ];
        }

        $left = [];
        foreach ($trace->left as $id => $why) {
            if (str_starts_with((string) $id, 'p:') && isset($records[$id]) && ! in_array($id, array_column($shown, 'id'), true)) {
                $left[] = ['title' => (string) $records[$id]['title'], 'key' => $why];
            }
            if (count($left) >= self::LEFT_SHOWN) {
                break;
            }
        }

        return [
            'query' => $typed,
            'read' => $this->readLines($trace),
            'results' => $results,
            'left' => $left,
            'left_count' => count(array_filter(array_keys($trace->left), fn ($id): bool => str_starts_with((string) $id, 'p:'))),
            'steps' => $trace->steps,
            'suggestions' => $this->suggestions($result, $results, $trace),
            'counts' => (array) ($result['counts'] ?? []),
            'held' => (int) ($result['held'] ?? 0),
            'asked_with' => array_slice(array_column($shown, 'external_id'), 0, self::SHOWN),
        ];
    }

    /**
     * The words the search went by, as the shopper typed them: those code left out ("הכי", "עד 500") are not.
     *
     * @return array<string, string> shown word => its normalized form
     */
    private function words(string $typed, string $searched): array
    {
        $searchedWords = array_values(array_filter(explode(' ', HebrewSearch::normalize($searched)), fn (string $w): bool => mb_strlen($w) > 1));
        $out = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $normal = HebrewSearch::normalize($word);
            if (in_array($normal, $searchedWords, true)) {
                $out[$word] = $normal;
            }
        }
        // what the search went by that was not typed as such (a question read for what it is about)
        foreach ($searchedWords as $normal) {
            if (! in_array($normal, $out, true)) {
                $out[(string) preg_replace_callback('/[כמנפצ]$/u', fn (array $m): string => self::FINALS[$m[0]], $normal)] = $normal;
            }
        }

        return $out;
    }

    /**
     * Why one result is there, each way it was found, as a line a store owner reads; detail is for the operator.
     *
     * @param  list<array<string, mixed>>  $found
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $words
     * @return list<array{key: string, params: array<string, string>, detail?: string}>
     */
    private function ways(array $found, ?CatalogProduct $product, array $record, array $words, bool $single): array
    {
        $ways = $single ? [['key' => 'single', 'params' => []]] : [];
        $seen = [];

        foreach ($found as $way) {
            $by = (string) $way['by'];
            if (isset($seen[$by])) {
                continue;
            }
            $seen[$by] = true;

            $add = match ($by) {
                'words' => [...$this->where($product, $record, $words), ...(($way['full'] ?? false) || count($words) < 2 ? [] : [['key' => 'some_words', 'params' => []]])],
                'meaning' => [['key' => 'meaning', 'params' => [], 'detail' => (string) ($way['similarity'] ?? '')]],
                'picture' => [['key' => 'picture', 'params' => [], 'detail' => (string) ($way['similarity'] ?? '')]],
                'description' => [['key' => 'description', 'params' => []]],
                'subject' => [['key' => 'subject', 'params' => ['category' => implode(', ', (array) ($way['categories'] ?? []))]]],
                'guide' => [['key' => 'guide', 'params' => ['guide' => (string) ($way['guide'] ?? '')]]],
                'resolved' => [['key' => 'resolved', 'params' => []]],
                default => [],
            };
            array_push($ways, ...$add);
        }

        return $ways === [] ? [['key' => 'other', 'params' => []]] : $ways;
    }

    /**
     * Where in the product each word is: its name, a category, one of the store's tags, its brand, a detail the store
     * gave, or what Lets learned of it (its picture, its tags by meaning, a synonym); a near spelling when none says it.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $words
     * @return list<array{key: string, params: array<string, string>}>
     */
    private function where(?CatalogProduct $product, array $record, array $words): array
    {
        $title = (string) ($record['title'] ?? $product?->title ?? '');
        $places = [];

        foreach ($words as $shown => $normal) {
            $place = match (true) {
                HebrewSearch::says($title, $normal) => ['title', ''],
                default => null,
            };
            foreach ($product === null || $place !== null ? [] : $product->categories as $category) {
                if (HebrewSearch::says((string) $category->name, $normal)) {
                    $place = ['category', (string) $category->name];
                    break;
                }
            }
            foreach ($product === null || $place !== null ? [] : (array) ($product->payload['tags'] ?? []) as $tag) {
                if (HebrewSearch::says((string) $tag, $normal)) {
                    $place = ['store_tag', (string) $tag];
                    break;
                }
            }
            if ($place === null && $product !== null && HebrewSearch::says((string) $product->brand, $normal)) {
                $place = ['brand', (string) $product->brand];
            }
            foreach ($product === null || $place !== null ? [] : $product->storeAttributes() as $attribute) {
                if (array_any($attribute['values'], fn (string $v): bool => HebrewSearch::says($v, $normal))) {
                    $place = ['attribute', $attribute['name']];
                    break;
                }
            }
            $place ??= HebrewSearch::says((string) ($record['kw'] ?? ''), $normal) ? ['lets', ''] : ['close', ''];
            $places[$place[0].'|'.$place[1]][] = (string) $shown;
        }

        $out = [];
        foreach ($places as $at => $said) {
            [$key, $where] = explode('|', $at, 2);
            $out[] = ['key' => 'words_'.$key, 'params' => ['words' => implode(', ', array_map(fn (string $w): string => '"'.$w.'"', $said)), 'where' => $where]];
        }

        return $out;
    }

    /** @return list<array{key: string, params: array<string, string>}> what code read from the words, before searching */
    private function readLines(SearchTrace $trace): array
    {
        $read = $trace->steps['read'] ?? [];
        $lines = [];
        $join = fn (array $list): string => implode(', ', array_map(fn ($w): string => '"'.$w.'"', $list));

        if ($read['short'] ?? false) {
            $lines[] = ['key' => 'short', 'params' => []];
        }
        if (isset($read['min_price']) || isset($read['max_price'])) {
            $lines[] = ['key' => 'price', 'params' => ['min' => (string) ($read['min_price'] ?? '—'), 'max' => (string) ($read['max_price'] ?? '—')]];
        }
        foreach (['without', 'facets', 'unknown'] as $key) {
            if (($read[$key] ?? []) !== []) {
                $lines[] = ['key' => $key, 'params' => ['words' => $join((array) $read[$key])]];
            }
        }
        if (($read['actions'] ?? []) !== []) {
            $lines[] = ['key' => 'actions', 'params' => ['actions' => implode(', ', (array) $read['actions'])]];
        }
        if (isset($read['sentence'])) {
            $lines[] = ['key' => 'sentence', 'params' => ['words' => (string) $read['sentence']]];
        }
        if (($read['about'] ?? '') !== '') {
            $lines[] = ['key' => 'about', 'params' => ['words' => (string) $read['about']]];
        }
        if ($read['question'] ?? false) {
            $lines[] = ['key' => 'question', 'params' => []];
        }
        if ($read['code'] ?? false) {
            $lines[] = ['key' => 'code', 'params' => []];
        }
        if ($trace->steps['meaning']['far'] ?? false) {
            $lines[] = ['key' => 'meaning_far', 'params' => []];
        }
        if (isset($trace->steps['subject']['wide'])) {
            $lines[] = ['key' => 'subject_wide', 'params' => ['category' => implode(', ', (array) $trace->steps['subject']['wide'])]];
        }

        return $lines;
    }

    /**
     * What the store could change so the next shopper finds better, from what this search did. Code only.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $results
     * @return list<array{key: string, params: array<string, string>, link?: string}>
     */
    private function suggestions(array $result, array $results, SearchTrace $trace): array
    {
        $out = [];
        $unknown = (array) ($trace->steps['read']['unknown'] ?? []);

        if ($results === [] && ! ($trace->steps['read']['short'] ?? false)) {
            $out[] = ['key' => 'nothing', 'params' => [], 'link' => 'synonyms'];
        }
        foreach ($unknown as $word) {
            $out[] = ['key' => 'unknown', 'params' => ['word' => (string) $word], 'link' => 'synonyms'];
        }
        if ($results !== [] && (int) ($result['held'] ?? 0) === 0 && str_contains(trim((string) ($result['query'] ?? '')), ' ')) {
            $out[] = ['key' => 'no_whole', 'params' => []];
        }

        $byMeaning = array_values(array_filter($results, fn (array $r): bool => ! array_any($r['ways'], fn (array $w): bool => str_starts_with($w['key'], 'words_') || $w['key'] === 'single')));
        foreach (array_slice($byMeaning, 0, self::MEANING_ONLY_SHOWN) as $r) {
            $out[] = ['key' => 'meaning_only', 'params' => ['title' => $r['title']]];
        }
        $close = array_values(array_filter($results, fn (array $r): bool => array_any($r['ways'], fn (array $w): bool => $w['key'] === 'words_close')));
        if ($close !== []) {
            $out[] = ['key' => 'close', 'params' => ['count' => (string) count($close)], 'link' => 'synonyms'];
        }
        $pictureless = array_values(array_filter($results, fn (array $r): bool => ($r['image'] ?? null) === null));
        if ($pictureless !== []) {
            $out[] = ['key' => 'no_image', 'params' => ['titles' => implode(', ', array_slice(array_column($pictureless, 'title'), 0, 3))]];
        }
        $outOfStock = count(array_filter($results, fn (array $r): bool => ! $r['in_stock']));
        if ($outOfStock > 0) {
            $out[] = ['key' => 'out_of_stock', 'params' => ['count' => (string) $outOfStock]];
        }

        return $out === [] && $results !== [] ? [['key' => 'fine', 'params' => []]] : $out;
    }

    /**
     * The question's way, as the store owner reads it, with the operator's detail (the models, the verdict, the cost,
     * what the writer was given), and what the store could add so it is answered.
     *
     * @param  array<string, mixed>  $tried  TriesQuestions::trial()'s
     * @return array<string, mixed>
     */
    private function answer(array $tried): array
    {
        $outcome = (string) ($tried['outcome'] ?? 'unavailable');
        $from = (string) ($tried['from'] ?? 'none');
        $trial = (array) ($tried['trial'] ?? []);
        $looks = (array) ($trial['looks'] ?? []);
        $answered = $outcome === 'answered';
        $stopped = $from === 'none' && in_array($outcome, ['support', 'out_of_scope', 'personal_data', 'blocked'], true);

        $steps = [['key' => $stopped ? 'stopped' : 'passed', 'params' => []]];
        if (! $stopped) {
            $steps[] = match (true) {
                isset($tried['set']) => ['key' => 'set', 'params' => ['count' => (string) count((array) ($tried['set']['ids'] ?? []))]],
                $from === 'code' => ['key' => 'code', 'params' => []],
                $from === 'bank' => ['key' => 'bank', 'params' => []],
                default => ['key' => 'not_code', 'params' => []],
            };
        }
        foreach ($from === 'model' || $from === 'page' ? $looks : [] as $at => $look) {
            $steps[] = ['key' => $at === 0 ? 'gathered' : 'second_look', 'params' => ['pages' => (string) count((array) $look['pages']), 'products' => (string) count((array) $look['products'])]];
        }
        if ($from === 'model' && isset($trial['writer_model'])) {
            $steps[] = ['key' => 'written', 'params' => []];
        }
        if ($from === 'model' && isset($trial['checker_model'])) {
            $steps[] = ['key' => 'checked', 'params' => []];
        }
        $steps[] = ['key' => 'outcome_'.(in_array($outcome, ['answered', 'no_match', 'no_info', 'out_of_scope', 'limit', 'unavailable', 'quoted', 'support', 'personal_data', 'blocked', 'invalid'], true) ? $outcome : 'unavailable'), 'params' => []];

        $suggestions = match ((string) ($trial['failure'] ?? '')) {
            'missing_info' => [['key' => 'missing_info', 'params' => [], 'link' => 'knowledge']],
            'retrieval' => [['key' => 'retrieval', 'params' => [], 'link' => 'knowledge']],
            'checker_rejected' => [['key' => 'checker_rejected', 'params' => [], 'link' => 'knowledge']],
            'scope' => [['key' => 'scope', 'params' => [], 'link' => 'knowledge']],
            'failed' => [['key' => 'failed', 'params' => []]],
            default => [],
        };
        if ($suggestions === [] && in_array($outcome, ['no_match', 'no_info'], true)) {
            $suggestions[] = ['key' => 'missing_info', 'params' => [], 'link' => 'knowledge'];
        }
        if ($answered && $from === 'bank') {
            $suggestions[] = ['key' => 'bank', 'params' => []];
        }
        if ($answered) {
            $suggestions[] = ['key' => 'wrong_answer', 'params' => [], 'link' => 'knowledge'];
        }
        if ($outcome === 'limit' || $outcome === 'unavailable') {
            $suggestions[] = ['key' => 'unavailable', 'params' => []];
        }

        return [
            'outcome' => $outcome,
            'from' => $from,
            'text' => (string) ($tried['answer'] ?? '') !== '' ? (string) $tried['answer'] : (string) ($tried['not_found'] ?? ''),
            'picks' => array_map(fn (array $p): array => ['title' => (string) ($p['title'] ?? ''), 'why' => (string) ($p['why'] ?? '')], (array) ($tried['picks'] ?? [])),
            'sources' => array_map(fn (array $s): array => ['title' => (string) ($s['title'] ?? ''), 'url' => $s['url'] ?? null], (array) ($tried['sources'] ?? [])),
            'steps' => $steps,
            'suggestions' => $suggestions,
            'tech' => array_filter([
                'reason' => $trial['reason'] ?? null,
                'failure' => $trial['failure'] ?? null,
                'writer' => $trial['writer_model'] ?? null,
                'checker' => $trial['checker_model'] ?? null,
                'verdict' => $trial['verdict'] ?? null,
                'prompt' => isset($trial['prompt_version']) ? 'v'.$trial['prompt_version'] : null,
                'cost_usd' => isset($trial['cost_usd']) ? (string) round((float) $trial['cost_usd'], 5) : null,
                'ms' => $trial['duration_ms'] ?? null,
                'run' => $trial['run_id'] ?? null,
                'context' => $trial['trace']['context'] ?? null,
            ], fn ($v): bool => $v !== null && $v !== ''),
            'looks' => $looks,
        ];
    }
}
