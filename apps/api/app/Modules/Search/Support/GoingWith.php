<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\ProductsGoingWith;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;

/**
 * The products of another kind that go with a product, read off the store's search index (ProductsGoingWith). Code only,
 * any store, no word list of its own: the kinds are the index's type filter (else its one-word categories), the shared
 * values its filters, the shared words its titles, keywords and the product's description.
 *
 * "יש טבעת שמתאימה לצמיד?" on a two-tone 14K chain-link bracelet (Rocks, 19.11) was answered with a paragraph and no ring:
 * on a product's page the writer had that product alone. Now the store's rings in stock, for the bracelet's audience,
 * each weighed by what it shares with the bracelet:
 *
 * - each filter value both carry (its metal, colour, karat, stone, style, collection), by how few products carry it: a
 *   value every product has says nothing. Never the kind itself, a sale, a size, a length or whom it is for; never a
 *   value a check refused for either; half for a value read weakly. A filter where both have values and none shared
 *   (gold beside silver) weighs against;
 * - each word of the product's title, keywords or description that few of the store's products say ("חוליות", "Jubilee",
 *   "וינטג'"), in any of its forms, found in the other's title or keywords, and a word of its title in the other's
 *   description too. Never a word that names a kind;
 * - how near its price is, a tie-breaker only.
 *
 * None of the kind in stock: none, never another kind instead (the store's rule: fewer or none, never unrelated).
 */
final class GoingWith implements ProductsGoingWith
{
    /** A word or value more than this share of the store's products carry tells nothing apart. */
    private const COMMON_SHARE = 0.3;

    /** Of the products of the kind, how many best by their index are read again with their descriptions. */
    private const POOL = 40;

    /** A word of the product's own title weighs this; one of its keywords or description this. */
    private const TITLE_WORD = 1.0;

    private const OTHER_WORD = 0.6;

    /** A value read weakly (search.evidence_index) counts this much of one read surely. */
    private const WEAK = 0.5;

    /** A filter where both have values and none is shared: this much against. */
    private const CONFLICT = 0.5;

    /** How much a price nearer the product's weighs at most: a tie-breaker, never a reason. */
    private const PRICE_NEAR = 0.3;

    /** Filters that never say a product goes with another: its kind, a sale, whom it is for, a length, a size. */
    private const NOT_SHARED = ['type', 'deal', 'audience', 'length', BuildSearchIndex::VARIANT_SIZE];

    /** A filter whose key says it is a size, a measure, a price or the stock. */
    private const MEASURE_KEY = '/(?:^|[_:\s-])(?:size|sizes|length|width|height|weight|price|stock|availability|sale|discount|deal)(?:$|[_:\s-])|מיד[הת]|מידות|אורך|רוחב|משקל|מחיר|מלאי|מבצע/iu';

    /** Words that tie a sentence together, in either language, never a motif. */
    private const LINK_WORDS = ['the', 'and', 'with', 'for', 'of', 'an', 'in', 'on', 'to', 'by', 'this', 'that', 'it', 'is', 'are', 'set', 'new',
        'עמ', 'של', 'את', 'על', 'או', 'גמ', 'זה', 'זו', 'הזה', 'הזאת', 'סט', 'כל', 'בלי', 'ללא', 'מאוד', 'יותר', 'אשר'];

    /** @var array<string, array{df: array<string, int>, keys: array<string, list<string>>, products: int}> by index hash */
    private static array $words = [];

    public function __construct(private readonly ProductKinds $kinds) {}

    public function goingWith(string $shopId, string $viewed, string $question, int $limit): ?array
    {
        $index = LoadedIndex::for($shopId);
        $own = $index['records']['p:'.$viewed] ?? null;
        if ($index === null || $own === null || trim($question) === '' || $limit < 1) {
            return null;
        }
        $facets = array_values((array) ($index['facets'] ?? []));
        $asked = self::askedKind($index, $facets, $question, $own);
        if ($asked === null) {
            return null;
        }

        $products = array_filter($index['records'], fn (array $r): bool => ($r['t'] ?? null) === 'product');
        $total = max(1, count($products));
        $counts = (array) ($index['counts'] ?? []);
        $words = self::words($index['hash'], $products);
        $ownCodes = self::codes($own);
        $ownWeak = array_map('intval', array_keys((array) ($own['ev'] ?? [])));
        $shareable = self::shareable($facets);

        // what the product says that few others do, by key, with how it writes it
        $ownTitle = self::keyed((string) ($own['title'] ?? ''));
        $ownOther = self::keyed((string) ($own['kw'] ?? '').' '.$this->description($viewed));
        $kindKeys = self::kindKeys($facets);
        $rare = fn (string $key): bool => ! isset($kindKeys[$key]) && ($words['df'][$key] ?? 0) >= 1 && ($words['df'][$key] ?? 0) <= max(1, (int) floor($total * self::COMMON_SHARE));
        $ownTitle = array_filter($ownTitle, $rare, ARRAY_FILTER_USE_KEY);
        $ownOther = array_diff_key(array_filter($ownOther, fn (string $key): bool => $rare($key) && ($words['df'][$key] ?? 0) >= 2, ARRAY_FILTER_USE_KEY), $ownTitle);
        $idf = fn (int $n): float => log(1 + $total / max(1, $n));
        $ownPrice = isset($own['pr']) ? (float) $own['pr'] : null;

        $scored = [];
        foreach ($products as $id => $record) {
            $codes = self::codes($record);
            if ($id === 'p:'.$viewed || (int) ($record['s'] ?? 0) !== 1 || array_intersect($codes, $asked['codes']) === []) {
                continue;
            }
            $score = 0.0;
            $shares = [];
            $weak = array_map('intval', array_keys((array) ($record['ev'] ?? [])));
            $sharedGroups = [];
            foreach (array_intersect($ownCodes, $codes) as $code) {
                $group = intdiv($code, 1000);
                if (! isset($shareable[$group]) || ! self::shareableValue($this->kinds, $facets[$group], $code % 1000)) {
                    continue;
                }
                $score += $idf((int) ($counts[$code] ?? 1)) * (in_array($code, $ownWeak, true) || in_array($code, $weak, true) ? self::WEAK : 1.0);
                $shares[] = (string) ($facets[$group]['v'][$code % 1000]['l'] ?? '');
                $sharedGroups[$group] = true;
            }
            // gold beside silver: a filter of what it is made of where both say, and differently
            foreach ($shareable as $group => $attribute) {
                if ($attribute && ! isset($sharedGroups[$group]) && self::inGroup($ownCodes, $group) && self::inGroup($codes, $group)) {
                    $score -= self::CONFLICT;
                }
            }
            $said = array_flip($words['keys'][$id] ?? []);
            $valueWords = self::valueKeys($shares);
            foreach ([[$ownTitle, self::TITLE_WORD], [$ownOther, self::OTHER_WORD]] as [$ownWords, $weight]) {
                foreach ($ownWords as $key => $surface) {
                    if (isset($said[$key]) && ! isset($valueWords[$key])) {
                        $score += $weight * $idf((int) $words['df'][$key]);
                        $shares[] = $surface;
                    }
                }
            }
            $price = isset($record['pr']) ? (float) $record['pr'] : null;
            $near = $ownPrice !== null && $ownPrice > 0 && $price !== null && $price > 0 ? max(0.0, 1 - abs(log($price / $ownPrice)) / log(4)) : 0.0;
            $scored[substr((string) $id, 2)] = ['score' => $score, 'near' => $near, 'shares' => array_values(array_unique(array_filter($shares)))];
        }

        $order = function (array &$scored): void {
            uksort($scored, fn (string $a, string $b): int => ($scored[$b]['score'] + self::PRICE_NEAR * $scored[$b]['near']) <=> ($scored[$a]['score'] + self::PRICE_NEAR * $scored[$a]['near']) ?: strcmp($a, $b));
        };
        $order($scored);

        // the best by the index read again with their descriptions: a word of the product's title in theirs ("חוליות")
        $pool = array_slice(array_keys($scored), 0, self::POOL);
        if ($pool !== [] && $ownTitle !== []) {
            foreach (CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $pool)->get() as $product) {
                $id = (string) $product->external_id;
                $text = array_flip(array_keys(self::keyed($product->shortDescription().' '.$product->description())));
                foreach ($ownTitle as $key => $surface) {
                    if (isset($text[$key]) && ! in_array($surface, $scored[$id]['shares'], true) && ! isset(self::valueKeys($scored[$id]['shares'])[$key])) {
                        $scored[$id]['score'] += self::OTHER_WORD * $idf((int) $words['df'][$key]);
                        $scored[$id]['shares'][] = $surface;
                    }
                }
            }
            $order($scored);
        }

        $ids = $this->forItsAudience($viewed, array_slice(array_map('strval', array_keys($scored)), 0, max($limit * 3, self::POOL)));
        $ids = array_slice($ids, 0, $limit);

        return [
            'kind' => $asked['label'],
            'ids' => $ids,
            'shares' => array_combine($ids, array_map(fn (string $id): array => $scored[$id]['shares'], $ids)) ?: [],
        ];
    }

    /**
     * The kind the question asks for, other than the product's own: the index's type values its words name (a word's forms,
     * the store's families; never a guess from a typo), else the kind a type's first word names ("טבעת" for "טבעת אירוסין"),
     * else its one-word categories. Null when it names none, or only the product's own ("is the bracelet 14K?").
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  array<string, mixed>  $own
     * @return array{codes: list<int>, label: string}|null
     */
    private static function askedKind(array $index, array $facets, string $question, array $own): ?array
    {
        $ownCodes = self::codes($own);
        foreach (['type', 'category'] as $key) {
            $at = array_search($key, array_map(fn (array $f): string => (string) ($f['k'] ?? ''), $facets), true);
            if (! is_int($at) || ($facets[$at]['v'] ?? []) === []) {
                continue;
            }
            $values = (array) $facets[$at]['v'];
            // a category names a kind in one word ("טבעות"); one of a campaign, a collection or several words says how
            $usable = $key === 'type' ? array_keys($values) : array_keys(array_filter($values, fn (array $v): bool => count(HebrewSearch::facetWords((string) ($v['l'] ?? ''))) === 1));
            if ($usable === []) {
                continue;
            }
            $ownHeads = [];
            foreach ($ownCodes as $code) {
                if (intdiv($code, 1000) === $at) {
                    $ownHeads[self::headKey((string) ($values[$code % 1000]['l'] ?? ''))] = true;
                }
            }
            if ($ownHeads === []) {
                // a product the index files under no value of this filter: its kind is what its title opens with
                if ($key === 'type' && ($head = self::headKey((string) ($own['title'] ?? ''))) !== '') {
                    $ownHeads[$head] = true;
                } else {
                    continue;
                }
            }
            $read = self::readKinds($index, $facets[$at], $usable, $question);
            $asked = array_values(array_filter($read, fn (int $i): bool => ! in_array($at * 1000 + $i, $ownCodes, true) && ! isset($ownHeads[self::headKey((string) ($values[$i]['l'] ?? ''))])));
            if ($asked !== []) {
                return ['codes' => array_map(fn (int $i): int => $at * 1000 + $i, $asked), 'label' => implode(' / ', array_values(array_unique(array_map(fn (int $i): string => (string) $values[$i]['l'], $asked))))];
            }
            if ($read !== []) {
                // it names only the product's own kind: a question about the product
                return null;
            }
        }

        return null;
    }

    /**
     * The values of one kind filter the question's words name, as places in it: readFacets on that filter alone (so no
     * other filter's value takes the word), by a word's forms or the store's families only; then any value whose first
     * word a word of the question is, in any form.
     *
     * @param  array<string, mixed>  $facet
     * @param  list<int>  $usable
     * @return list<int>
     */
    private static function readKinds(array $index, array $facet, array $usable, string $question): array
    {
        $values = (array) $facet['v'];
        $only = ['k' => (string) $facet['k'], 'l' => (string) ($facet['l'] ?? ''), 'v' => array_map(fn (int $i): array => $values[$i], $usable)];
        $ctx = HebrewSearch::readContext($index);
        unset($ctx['counts']);
        $read = [];
        foreach (HebrewSearch::readFacets($question, [$only], $ctx) as $r) {
            if (in_array($r['how'], ['exact', 'form', 'family'], true) && ! $r['part']) {
                $read[] = $usable[$r['code'] % 1000];
            }
        }
        if ($read !== []) {
            return array_values(array_unique($read));
        }
        $typed = [];
        foreach (HebrewSearch::facetWords($question) as $word) {
            $typed[self::formOf($word)] = true;
            if (mb_strlen($word) > 3 && preg_match('/^[והבלמש][א-ת]+$/u', $word) === 1) {
                $typed[self::formOf(mb_substr($word, 1))] = true;
            }
        }
        foreach ($usable as $i) {
            $head = self::headKey((string) ($values[$i]['l'] ?? ''));
            if ($head !== '' && mb_strlen($head) >= 2 && isset($typed[$head])) {
                $read[] = $i;
            }
        }

        return $read;
    }

    /** A value's first word, in the one form of its singular and plural ("טבעות" and "טבעת" one, "rings" "ring"). */
    private static function headKey(string $label): string
    {
        $first = HebrewSearch::facetWords($label)[0] ?? '';

        return $first === '' ? '' : self::formOf($first);
    }

    /** One form of a word, for kinds: its singular and plural one. */
    private static function formOf(string $word): string
    {
        return HebrewSearch::formKey($word);
    }

    /**
     * Which filters may say a product goes with another, by their place: true where it says what a product is made of or
     * how it looks (an attribute, where values that differ weigh against), false where it only files it (a category, a
     * tag, a brand, the store's tags).
     *
     * @param  list<array<string, mixed>>  $facets
     * @return array<int, bool>
     */
    private static function shareable(array $facets): array
    {
        $out = [];
        foreach ($facets as $at => $facet) {
            $key = (string) ($facet['k'] ?? '');
            if (in_array($key, self::NOT_SHARED, true) || preg_match(self::MEASURE_KEY, $key) === 1 || preg_match(self::MEASURE_KEY, (string) ($facet['l'] ?? '')) === 1) {
                continue;
            }
            $values = (array) ($facet['v'] ?? []);
            // a yes-or-no filter is one value: no other value to differ by
            $out[$at] = ! in_array($key, ['category', 'brand', 'store_tags'], true) && ! str_starts_with($key, 'tag:') && ! str_starts_with($key, 'field:') && count($values) > 1;
        }

        return $out;
    }

    /** A value of a filter that files products by a campaign, a sale or whom they are for says nothing of a match. */
    private static function shareableValue(ProductKinds $kinds, array $facet, int $value): bool
    {
        $label = (string) ($facet['v'][$value]['l'] ?? '');
        $key = (string) ($facet['k'] ?? '');
        if ($label === '' || preg_match('/^(?:אחר|other)$/iu', trim($label)) === 1) {
            return false;
        }
        if (in_array($key, ['category', 'store_tags'], true) || str_starts_with($key, 'tag:')) {
            return ! $kinds->campaign($label) && ! $kinds->onlyWhom($label);
        }

        return true;
    }

    /** @return list<int> the filter values a record carries, less those a check refused for it */
    private static function codes(array $record): array
    {
        return array_values(array_diff(array_map('intval', (array) ($record['f'] ?? [])), array_map('intval', (array) ($record['no'] ?? []))));
    }

    /** @param  list<int>  $codes */
    private static function inGroup(array $codes, int $group): bool
    {
        return array_any($codes, fn (int $code): bool => intdiv($code, 1000) === $group);
    }

    /**
     * The words of the kind filters' values, by key: never a motif ("טבעת" beside "טבעות").
     *
     * @param  list<array<string, mixed>>  $facets
     * @return array<string, true>
     */
    private static function kindKeys(array $facets): array
    {
        $out = [];
        foreach ($facets as $facet) {
            if (! in_array((string) ($facet['k'] ?? ''), ['type', 'category'], true)) {
                continue;
            }
            foreach ((array) ($facet['v'] ?? []) as $value) {
                if (($facet['k'] ?? '') === 'category' && count(HebrewSearch::facetWords((string) ($value['l'] ?? ''))) !== 1) {
                    continue;
                }
                foreach (array_keys(self::keyed((string) ($value['l'] ?? ''))) as $key) {
                    $out[$key] = true;
                }
            }
        }

        return $out;
    }

    /**
     * The words of shared values, by key: a word they already say ("זהב" of "זהב 14K") is not said again as a word.
     *
     * @param  list<string>  $labels
     * @return array<string, true>
     */
    private static function valueKeys(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            foreach (array_keys(self::keyed($label)) as $key) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * A text's words by one key for all their forms, each with the first way the text writes it: the singular and the
     * plural one ("חוליות", "חוליה"), the vowel letters dropped ("וינטאג'", "וינטג'"), a letter before it ("בחוליות")
     * read without it too. Never a number alone, a word of two letters, a word that says nothing.
     *
     * @return array<string, string>
     */
    private static function keyed(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}\'"׳״`]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $word = HebrewSearch::normalize($raw);
            if ($word === '' || str_contains($word, ' ') || mb_strlen($word) < 3 || preg_match('/^\d+$/', $word) === 1
                || HebrewSearch::saysNothing($word) || in_array($word, self::LINK_WORDS, true)) {
                continue;
            }
            // the quotes around it off, by letters: trim() would cut a byte of "׳" and break the text
            $surface = (string) preg_replace('/^[\'"׳״`]+|[\'"׳״`]+$/u', '', $raw);
            foreach (self::forms($word) as $key) {
                $out[$key] ??= $surface;
            }
        }

        return $out;
    }

    /** @return list<string> the keys a normalized word is known by */
    private static function forms(string $word): array
    {
        $key = fn (string $w): string => preg_match('/^[א-ת]+$/u', $w) === 1 ? HebrewSearch::skeleton(HebrewSearch::formKey($w)) : HebrewSearch::formKey($w);
        $keys = [$key($word)];
        if (mb_strlen($word) > 4 && preg_match('/^[והבלמש][א-ת]+$/u', $word) === 1) {
            $keys[] = $key(mb_substr($word, 1));
        }

        return array_values(array_unique(array_filter($keys, fn (string $k): bool => mb_strlen($k) >= 2)));
    }

    /**
     * Each product's words by key (its title and keywords) and how many products say each, once per index.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array{df: array<string, int>, keys: array<string, list<string>>, products: int}
     */
    private static function words(string $hash, array $products): array
    {
        if (isset(self::$words[$hash])) {
            return self::$words[$hash];
        }
        $df = [];
        $keys = [];
        foreach ($products as $id => $record) {
            $own = array_keys(self::keyed((string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? '')));
            $keys[(string) $id] = array_map('strval', $own);
            foreach ($own as $key) {
                $df[(string) $key] = ($df[(string) $key] ?? 0) + 1;
            }
        }
        if (count(self::$words) >= 8) {
            array_shift(self::$words);
        }

        return self::$words[$hash] = ['df' => $df, 'keys' => $keys, 'products' => count($products)];
    }

    /** What the product's own page says of it: its short description and its description. */
    private function description(string $externalId): string
    {
        $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first();

        return $product === null ? '' : $product->shortDescription().' '.$product->description();
    }

    /**
     * Of these, those for the product's people, as the store says whom each is for (ProductKinds::audiences): a kids' piece
     * beside kids' pieces only, a woman's never beside a man's alone; one the store says nothing of stays. The same rule as
     * the drawer's similar items (SearchCatalog::similar).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function forItsAudience(string $viewed, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $whom = CatalogProduct::query()->whereIn('external_id', [$viewed, ...$ids])->with('categories:id,name')->get(['id', 'shop_id', 'external_id', 'title', 'brand', 'payload'])
            ->mapWithKeys(fn (CatalogProduct $p): array => [(string) $p->external_id => $this->kinds->audiences($p)])->all();
        $own = $whom[$viewed] ?? [];
        $young = fn (array $a): bool => array_intersect($a, ['kids', 'babies']) !== [];
        $side = fn (array $a): ?string => in_array('women', $a, true) === in_array('men', $a, true) ? null : (in_array('women', $a, true) ? 'women' : 'men');
        $anyYoung = array_filter($whom, $young) !== [];

        return array_values(array_filter($ids, function (string $id) use ($whom, $own, $young, $side, $anyYoung): bool {
            $theirs = $whom[$id] ?? [];
            if ($anyYoung && $young($theirs) !== $young($own)) {
                return false;
            }

            return $side($own) === null || $side($theirs) === null || $side($theirs) === $side($own);
        }));
    }
}
