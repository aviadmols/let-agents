<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\ProductsGoingWith;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\GoesWithPartners;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Tenancy\Models\Shop;

/**
 * The products of another kind that go with a product, read off the store's search index at question time
 * (ProductsGoingWith): code finds the candidates and hands them to the answer prompt, which composes the answer. Code only,
 * any store, no word list of its own: the kinds are the index's type filter (else its one-word categories), the shared
 * values its filters, the shared words its titles, keywords and the product's description.
 *
 * Two things are asked, one pipeline:
 *
 * - a match ("יש טבעת שמתאימה לצמיד?" on a two-tone 14K chain-link bracelet, Rocks 19.11, answered with a paragraph and no
 *   ring): the kind the question names, its products in stock, those sharing most with the product first;
 * - a complement, no kind named ("מה עוד צריך למקדחה?", "what goes with this dress?"): the products of other kinds than
 *   the product's own, never the same kind, those that share something with it, ranked the same way.
 *
 * Each candidate is weighed by what it shares with the product:
 *
 * - the store pairing them itself (a complement relation: its cross-sells, what is bought together; GoesWithPartners,
 *   where the shop lets them be read): most of all;
 * - each filter value both carry (its metal, colour, karat, stone, style, collection, brand), by how few products carry it: a
 *   value every product has says nothing. Never the kind itself, a sale, a size, a length or whom it is for; never a
 *   value a check refused for either; half for a value read weakly. A filter where both have values and none shared
 *   (gold beside silver) weighs against;
 * - the same vendor (the brand the store writes on both): the same system, in a store of tools;
 * - each word of the product's title, keywords or description that few of the store's products say ("חוליות", "Jubilee",
 *   "Makita"), in any of its forms, found in the other's title or keywords, and a word of its title in the other's
 *   description too. Never a word that names a kind;
 * - how near its price is, a tie-breaker only.
 *
 * None of the kind in stock, or nothing of another kind that shares anything: none, never another product instead (the
 * store's rule: fewer or none, never unrelated).
 */
final class GoingWith implements ProductsGoingWith
{
    /** A word or value more than this share of the store's products carry tells nothing apart. */
    private const COMMON_SHARE = 0.3;

    /** Of the products of the kind, how many best by their index are read again with their descriptions and vendors. */
    private const POOL = 40;

    /** A word of the product's own title weighs this; one of its keywords or description this. */
    private const TITLE_WORD = 1.0;

    private const OTHER_WORD = 0.6;

    /** A value read weakly (search.evidence_index) counts this much of one read surely. */
    private const WEAK = 0.5;

    /** A filter where both have values and none is shared: this much against. */
    private const CONFLICT = 0.5;

    /** The store pairing them itself (a complement relation) weighs this, and its score on top. */
    private const PAIRED = 3.0;

    /** The same vendor on both (the same system: Makita with Makita) weighs this. */
    private const VENDOR = 2.0;

    /** How much a price nearer the product's weighs at most: a tie-breaker, never a reason. */
    private const PRICE_NEAR = 0.3;

    /** Filters that never say a product goes with another: its kind, a sale, whom it is for, a length, a size. */
    private const NOT_SHARED = ['type', 'deal', 'audience', 'length', BuildSearchIndex::VARIANT_SIZE];

    /** A filter whose key says it is a size, a measure, a price or the stock. */
    private const MEASURE_KEY = '/(?:^|[_:\s-])(?:size|sizes|length|width|height|weight|price|stock|availability|sale|discount|deal)(?:$|[_:\s-])|מיד[הת]|מידות|אורך|רוחב|משקל|מחיר|מלאי|מבצע/iu';

    /** A filing name that tells where a product stands in the store: its price or a range of prices, newly in, a best seller. */
    private const STANDING = '/מחיר|לאחרונה|הגיע|רבי?\s*(?:ה)?מכר|הנמכר|נמכרי|פופולר|מומלצ|במלאי|₪|ש["״]ח|שקל|(?:^|\s)(?:עד|מתחת|מעל|החל)\s*(?:מ|ל)?-?\s*\d|\b(?:price\w*|nis|best\s*sell\w*|bestsell\w*|arriv\w*|popular|featured|in\s*stock|under\s*\d|up\s*to\s*\d|from\s*\d)\b|[$€£]/iu';

    /** Words that measure a product (its size, width, thickness, shape), never a motif: "בגודל", "ובעובי", "בצורת". */
    private const MEASURE_WORDS = ['גודל', 'עובי', 'צורה', 'צורת', 'רוחב', 'אורך', 'קוטר', 'משקל', 'מידה', 'מידות', 'גדלים', 'גדלי', 'גובה', 'עומק', 'נפח',
        'size', 'sizes', 'width', 'thickness', 'shape', 'length', 'diameter', 'weight', 'height', 'depth', 'volume'];

    /** Words that tie a sentence together, in either language, never a motif. */
    private const LINK_WORDS = ['the', 'and', 'with', 'for', 'of', 'an', 'in', 'on', 'to', 'by', 'this', 'that', 'it', 'is', 'are', 'set', 'new',
        'עמ', 'של', 'את', 'על', 'או', 'גמ', 'זה', 'זו', 'הזה', 'הזאת', 'סט', 'כל', 'בלי', 'ללא', 'מאוד', 'יותר', 'אשר'];

    /**
     * Asks what is used with the product, naming no kind: "מה עוד צריך", "מה הולך עם", "מה משלים", "what else do I need",
     * "what goes with". On the question normalized (no final letters).
     */
    private const ASKS_COMPLEMENT = '/(?:^|[^\p{L}])(?:מה\s+(?:עוד|הולכ\S*|מתאימ\S*|משלימ\S*|צריכ\S*|נחוצ\S*|כדאי|מומלצ\S*)|עוד\s+(?:משהו|דברימ|מוצרימ|צריכ\S*|נחוצ\S*)|צריכ\S*\s+(?:עוד|גמ|בנוספ|איתו|איתה|לזה)|(?:הולכ\S*|משלימ\S*|מתאימ\S*|ביחד|יחד)\s+(?:עמ|ל)|להשלימ|what\s+(?:else|goes|pairs|do\s+i\s+need|should\s+i)|anything\s+else|go(?:es)?\s+with|pairs?\s+with|complete\s+the|need\s+(?:with|for\s+(?:this|it|that))|accessor(?:y|ies))(?:[^\p{L}]|$)/iu';

    /** @var array<string, array{df: array<string, int>, keys: array<string, list<string>>, products: int}> by index hash */
    private static array $words = [];

    public function __construct(private readonly ProductKinds $kinds, private readonly GoesWithPartners $partners) {}

    public function asksAnotherKind(string $shopId, string $viewed, string $question): bool
    {
        $index = LoadedIndex::for($shopId);
        $own = $index['records']['p:'.$viewed] ?? null;
        if ($index === null || $own === null || trim($question) === '') {
            return false;
        }

        return self::askedKind($index, array_values((array) ($index['facets'] ?? [])), $question, $own) !== null;
    }

    public function goingWith(string $shopId, string $viewed, string $question, int $limit, array $sources = []): ?array
    {
        $index = LoadedIndex::for($shopId);
        $own = $index['records']['p:'.$viewed] ?? null;
        if ($index === null || $own === null || trim($question) === '' || $limit < 1) {
            return null;
        }
        $use = ['relations' => (bool) ($sources['relations'] ?? true), 'look' => (bool) ($sources['look'] ?? true)];
        $facets = array_values((array) ($index['facets'] ?? []));
        $asked = self::askedKind($index, $facets, $question, $own);
        $complement = preg_match(self::ASKS_COMPLEMENT, HebrewSearch::normalize($question)) === 1;
        if ($asked === null && ! $complement) {
            return null;
        }

        // the products the store itself pairs with it
        $paired = [];
        if ($use['relations']) {
            foreach ($this->partners->complementsOf($shopId, $viewed) as $relation) {
                $paired[(string) $relation['external_id']] ??= (int) $relation['score'];
            }
        }

        $products = array_filter($index['records'], fn (array $r): bool => ($r['t'] ?? null) === 'product');
        $total = max(1, count($products));
        $counts = (array) ($index['counts'] ?? []);
        $words = self::words($index['hash'], $products);
        $ownCodes = self::codes($own);
        $ownWeak = array_map('intval', array_keys((array) ($own['ev'] ?? [])));
        $shareable = self::shareable($facets);
        $anchor = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $viewed)->first();
        // a complement with no kind named: anything of another kind than the product's own, never the same kind
        $sameKind = $asked === null ? self::sameKindAs($facets, $own) : null;

        // what the product says that few others do, by key, with how it writes it
        $ownTitle = self::keyed((string) ($own['title'] ?? ''));
        $ownOther = self::keyed((string) ($own['kw'] ?? '').' '.($anchor === null ? '' : $anchor->shortDescription().' '.$anchor->description()));
        // ...never a word of the kinds, nor of a name that files where products stand ("הגיעו לאחרונה", "מחיר מלא")
        $kindKeys = self::kindKeys($facets) + self::standingKeys($facets);
        $rare = fn (string $key): bool => ! isset($kindKeys[$key]) && ($words['df'][$key] ?? 0) >= 1 && ($words['df'][$key] ?? 0) <= max(1, (int) floor($total * self::COMMON_SHARE));
        $ownTitle = array_filter($ownTitle, $rare, ARRAY_FILTER_USE_KEY);
        $ownOther = array_diff_key(array_filter($ownOther, fn (string $key): bool => $rare($key) && ($words['df'][$key] ?? 0) >= 2, ARRAY_FILTER_USE_KEY), $ownTitle);
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
        // a keyword in a script neither the product's name nor the store writes in is for search engines, never a motif
        // ("золотые" in a Hebrew store's keywords, Rocks 19.11)
        $written = self::writtenIn((string) ($own['title'] ?? ''), $locale);
        $ownOther = array_filter($ownOther, fn (string $surface): bool => preg_match($written, $surface) === 1);
        $idf = fn (int $n): float => log(1 + $total / max(1, $n));
        $ownPrice = isset($own['pr']) ? (float) $own['pr'] : null;
        $pairedLabel = (string) __('search::storefront.goes_with_paired', [], $locale);

        $scored = [];
        foreach ($products as $id => $record) {
            $external = substr((string) $id, 2);
            $codes = self::codes($record);
            $ofKind = $asked !== null ? array_intersect($codes, $asked['codes']) !== [] : ! $sameKind($record);
            $isPaired = isset($paired[$external]) && $ofKind;
            if ($id === 'p:'.$viewed || (int) ($record['s'] ?? 0) !== 1 || ! $ofKind || ! ($use['look'] || $isPaired)) {
                continue;
            }
            $score = 0.0;
            $shares = [];
            if ($isPaired) {
                $score += self::PAIRED + min(300, $paired[$external]) / 100;
                $shares[] = $pairedLabel;
            }
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
            $scored[$external] = ['score' => $score, 'near' => $near, 'shares' => array_values(array_unique(array_filter($shares)))];
        }

        $order = function (array &$scored): void {
            uksort($scored, fn (string $a, string $b): int => ($scored[$b]['score'] + self::PRICE_NEAR * $scored[$b]['near']) <=> ($scored[$a]['score'] + self::PRICE_NEAR * $scored[$a]['near']) ?: strcmp($a, $b));
        };
        $order($scored);

        // the best by the index read again with their descriptions and vendors: a word of the product's title in theirs
        // ("חוליות"), the same vendor on both ("Makita")
        $pool = array_slice(array_keys($scored), 0, self::POOL);
        $ownVendor = mb_strtolower(trim((string) ($anchor?->brand ?? '')));
        if ($pool !== [] && ($ownTitle !== [] || $ownVendor !== '')) {
            foreach (CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $pool)->get() as $product) {
                $id = (string) $product->external_id;
                $text = array_flip(array_keys(self::keyed($product->shortDescription().' '.$product->description())));
                foreach ($ownTitle as $key => $surface) {
                    if (isset($text[$key]) && ! in_array($surface, $scored[$id]['shares'], true) && ! isset(self::valueKeys($scored[$id]['shares'])[$key])) {
                        $scored[$id]['score'] += self::OTHER_WORD * $idf((int) $words['df'][$key]);
                        $scored[$id]['shares'][] = $surface;
                    }
                }
                if ($ownVendor !== '' && mb_strtolower(trim((string) $product->brand)) === $ownVendor) {
                    $scored[$id]['score'] += self::VENDOR;
                    if (! in_array((string) $anchor->brand, $scored[$id]['shares'], true) && ! isset(self::valueKeys($scored[$id]['shares'])[HebrewSearch::formKey($ownVendor)])) {
                        $scored[$id]['shares'][] = (string) $anchor->brand;
                    }
                }
            }
            $order($scored);
        }

        // of another kind than the product's, a candidate is one that shares something with it: never a product that only
        // happens to be of another kind (the store's rule: fewer or none, never unrelated)
        if ($asked === null) {
            $scored = array_filter($scored, fn (array $s): bool => $s['score'] > 0);
        }

        $ids = $this->forItsAudience($viewed, array_slice(array_map('strval', array_keys($scored)), 0, max($limit * 3, self::POOL)));
        $ids = array_slice($ids, 0, $limit);

        return [
            'kind' => $asked['label'] ?? '',
            'ids' => $ids,
            'shares' => array_combine($ids, array_map(fn (string $id): array => $scored[$id]['shares'], $ids)) ?: [],
            'complement' => $complement,
        ];
    }

    /**
     * Whether a record is of the product's own kind: it carries a value of the type filter the product carries (a kind
     * filed in several words too), else, where the product is filed under no type, its title opens with the same word.
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  array<string, mixed>  $own
     * @return callable(array<string, mixed>): bool
     */
    private static function sameKindAs(array $facets, array $own): callable
    {
        $typeAt = array_search('type', array_map(fn (array $f): string => (string) ($f['k'] ?? ''), $facets), true);
        $ownTypes = is_int($typeAt) ? array_values(array_filter(self::codes($own), fn (int $code): bool => intdiv($code, 1000) === $typeAt)) : [];
        $ownHead = self::headKey((string) ($own['title'] ?? ''));

        return function (array $record) use ($ownTypes, $ownHead): bool {
            if ($ownTypes !== []) {
                return array_intersect(self::codes($record), $ownTypes) !== [];
            }

            return $ownHead !== '' && self::headKey((string) ($record['title'] ?? '')) === $ownHead;
        };
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
    public static function shareable(array $facets): array
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
    public static function shareableValue(ProductKinds $kinds, array $facet, int $value): bool
    {
        $label = (string) ($facet['v'][$value]['l'] ?? '');
        $key = (string) ($facet['k'] ?? '');
        if ($label === '' || preg_match('/^(?:אחר|other)$/iu', trim($label)) === 1) {
            return false;
        }
        if (in_array($key, ['category', 'store_tags'], true) || str_starts_with($key, 'tag:')) {
            return self::filesWhatItIs($kinds, $label);
        }

        return true;
    }

    /**
     * Whether a category's or tag's name says what a product is ("זהב 14 קארט", "עגילים זהב צהוב"), not where it stands in
     * the store: a campaign or a season, whom it is for, its price or a price range, newly in, a best seller. A number
     * is a campaign's only as a year or a percent: "זהב 14 קארט" is gold, "מחיר מלא" is no match (Rocks, 19.11: the
     * earrings' necklaces were offered as "שרשרת · מחיר מלא", and the karat they shared was refused for its digits).
     */
    private static function filesWhatItIs(ProductKinds $kinds, string $label): bool
    {
        if (preg_match(self::STANDING, $label) === 1 || $kinds->onlyWhom($label)) {
            return false;
        }
        $probe = preg_match('/%|(?<!\d)(?:19|20)\d\d(?!\d)/u', $label) === 1 ? $label : trim((string) preg_replace('/\d+(?:[.,]\d+)?/u', ' ', $label));

        return preg_match('/\p{L}/u', $probe) === 1 && ! $kinds->campaign($probe);
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
     * The words of the categories' and tags' names that tell where a product stands in the store (STANDING), by key.
     *
     * @param  list<array<string, mixed>>  $facets
     * @return array<string, true>
     */
    private static function standingKeys(array $facets): array
    {
        $out = [];
        foreach ($facets as $facet) {
            $key = (string) ($facet['k'] ?? '');
            if (! in_array($key, ['category', 'store_tags'], true) && ! str_starts_with($key, 'tag:')) {
                continue;
            }
            foreach ((array) ($facet['v'] ?? []) as $value) {
                if (preg_match(self::STANDING, (string) ($value['l'] ?? '')) === 1) {
                    foreach (array_keys(self::keyed((string) $value['l'])) as $word) {
                        $out[$word] = true;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The pattern of a word written in the scripts the product's name uses, Latin (names, brands) and the store's language.
     */
    private static function writtenIn(string $title, string $locale): string
    {
        $scripts = ['Latin' => true];
        foreach (['Hebrew', 'Arabic', 'Cyrillic', 'Greek'] as $script) {
            if (preg_match('/\p{'.$script.'}/u', $title) === 1) {
                $scripts[$script] = true;
            }
        }
        $byLocale = ['he' => 'Hebrew', 'ar' => 'Arabic', 'ru' => 'Cyrillic', 'uk' => 'Cyrillic', 'el' => 'Greek'];
        if (isset($byLocale[strtolower(substr($locale, 0, 2))])) {
            $scripts[$byLocale[strtolower(substr($locale, 0, 2))]] = true;
        }

        return '/^(?:[^\p{L}]|'.implode('|', array_map(fn (string $s): string => '\p{'.$s.'}', array_keys($scripts))).')+$/u';
    }

    /** Whether a normalized word is a measure (MEASURE_WORDS), with up to two letters before it ("ובעובי", "בגודל"). */
    private static function measures(string $word): bool
    {
        static $measures = null;
        $measures ??= array_flip(array_map(fn (string $w): string => HebrewSearch::normalize($w), self::MEASURE_WORDS));
        for ($i = 0; $i <= 2 && mb_strlen($word) - $i >= 3; $i++) {
            $rest = mb_substr($word, $i);
            if (isset($measures[$rest])) {
                return true;
            }
            if (preg_match('/^[והבלמשכ]/u', $rest) !== 1) {
                return false;
            }
        }

        return false;
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
                || HebrewSearch::saysNothing($word) || in_array($word, self::LINK_WORDS, true) || self::measures($word)
                // a word that tells where a product stands ("הגיעו", "לאחרונה" in its keywords) is never a motif
                || preg_match(self::STANDING, $word) === 1) {
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
