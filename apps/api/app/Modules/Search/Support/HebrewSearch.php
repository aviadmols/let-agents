<?php

namespace App\Modules\Search\Support;

/**
 * Typo-tolerant Hebrew search without a model: word vectors made of three-letter pieces, plus a
 * spelling stem and a sound key, compared by cosine.
 *
 * The algorithm, its thresholds and its weights come unchanged from the search that was built and
 * measured on the pilot store (docs/search-mechanism.md there). resources/search/let-agents-search.js
 * runs the same algorithm in the browser for instant suggestions; the two must return the same
 * ids in the same order, so a change here is a change there, and SearchEngineParityTest checks
 * both against the same fixture.
 *
 *   $index = HebrewSearch::build($items);        // [['id' => 'p:12', 'title' => …, 'keywords' => …], …]
 *   $hits  = HebrewSearch::search($index, 'מקיטא'); // [['id' => …, 'title' => …, 'score' => …], …] best first
 */
final class HebrewSearch
{
    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    private const ALIKE = ['ט' => 'ת', 'ק' => 'כ', 'ח' => 'כ', 'ע' => 'א', 'ס' => 'ש', 'ה' => 'א'];

    private const KEYBOARD = [
        'e' => 'ק', 'r' => 'ר', 't' => 'א', 'y' => 'ט', 'u' => 'ו', 'i' => 'נ', 'o' => 'מ', 'p' => 'פ',
        'a' => 'ש', 's' => 'ד', 'd' => 'ג', 'f' => 'כ', 'g' => 'ע', 'h' => 'י', 'j' => 'ח', 'k' => 'ל', 'l' => 'כ', ';' => 'פ',
        'z' => 'ז', 'x' => 'ס', 'c' => 'ב', 'v' => 'ה', 'b' => 'נ', 'n' => 'מ', 'm' => 'צ', ',' => 'ת', '.' => 'צ',
    ];

    /** Words a question wraps around what it is about ("מה הכי טוב ל…"). */
    private const FILLER = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהמ', 'איפה', 'היכנ', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
        'אפשר', 'ניתנ', 'יש', 'צריכ', 'כדאי', 'מותר', 'מתאימ', 'מתאימה', 'מתאימימ', 'הכי', 'לי', 'לנו', 'עמ', 'של', 'את', 'על', 'ליד', 'זה', 'זו',
        'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גמ', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my',
        'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

    /**
     * A colour is one word whatever its gender and number: "מברגה שחורה" finds a screwdriver whose
     * colour is "שחור". Normalized (no final letters). Brown is left out: "חומה" is also a wall.
     */
    private const COLORS = [['שחור', 'שחורה', 'שחורימ', 'שחורות'], ['לבנ', 'לבנה', 'לבנימ', 'לבנות'], ['אדומ', 'אדומה', 'אדומימ', 'אדומות'], ['כחול', 'כחולה', 'כחולימ', 'כחולות'], ['ירוק', 'ירוקה', 'ירוקימ', 'ירוקות'], ['צהוב', 'צהובה', 'צהובימ', 'צהובות'], ['אפור', 'אפורה', 'אפורימ', 'אפורות'], ['כתומ', 'כתומה', 'כתומימ', 'כתומות'], ['סגול', 'סגולה', 'סגולימ', 'סגולות'], ['ורוד', 'ורודה', 'ורודימ', 'ורודות'], ['כסופ', 'כסופה', 'כסופימ', 'כסופות'], ['זהוב', 'זהובה', 'זהובימ', 'זהובות'], ['שקופ', 'שקופה', 'שקופימ', 'שקופות']];

    /** @var array<string, string>|null form => the family's first form */
    private static ?array $colorOf = null;

    private static function colorOf(string $word): ?string
    {
        if (self::$colorOf === null) {
            self::$colorOf = [];
            foreach (self::COLORS as $forms) {
                foreach ($forms as $form) {
                    self::$colorOf[$form] = $forms[0];
                }
            }
        }

        return self::$colorOf[$word] ?? null;
    }

    /** Words that say what kind of word follows ("בצבע שחור", "בגוון אפור", "בגימור מט"), normalized. */
    private const DESCRIBES = ['בצבע', 'בגוונ', 'בגימור'];

    /**
     * Words of one family, in every shop: a shopper's word and the store's are the same subject
     * ("גינה" and the store's category "גינון"), or the same word spelled two ways ("השקיה",
     * "השקייה"). Each is added to whatever holds another (BuildSearchIndex). Only families whose
     * words mean nothing else.
     */
    public const FAMILIES = [
        ['גינה', 'גינון', 'גינות', 'גנן', 'גננות'],
        ['השקיה', 'השקייה'],
        ['ניקוי', 'ניקיון', 'נקיון'],
        ['ריהוט', 'רהיטים'],
        ['אמבטיה', 'אמבט'],
        ['מקלחת', 'מקלחון'],
        ['תאורה', 'תאורת'],
        ['אינסטלציה', 'אינסטלטור'],
    ];

    /** How much a generic word counts in a record's score, against 1 for a word that says what. */
    public const GENERIC_WEIGHT = 0.2;

    /** Words that only say "things" ("כלים לגינה", "ציוד לגינה", "מוצרים לניקוי"), normalized. */
    private const GENERIC = ['כלימ', 'כלי', 'ציוד', 'מוצרימ', 'מוצר', 'אביזרימ', 'אביזר', 'פריטימ', 'פריט', 'דברימ', 'חפצימ', 'things', 'products', 'items', 'stuff'];

    /**
     * What a sentence is about, in the catalogue's words: question and filler words dropped, then
     * every word no product or page holds anything close to ("ביתית", "וזולה"). "מה הכי טוב
     * למברגה ביתית וזולה" is "למברגה". Empty when nothing is left.
     */
    public static function aboutWords(array $index, string $query): string
    {
        $words = array_filter(explode(' ', self::normalize($query)), fn (string $w): bool => $w !== '' && ! in_array($w, self::FILLER, true));

        return implode(' ', array_filter($words, fn (string $w): bool => mb_strlen($w) >= 2 && self::closeWords($index, $w) !== []));
    }

    /**
     * What a question asks about: its first word that is not a question or filler word and that
     * the catalogue knows. "איזה עץ מתאים לבנית פרגולה בחוץ" asks about "עץ"; the pergola is
     * what it is for. Null when nothing is left.
     */
    public static function subject(array $index, string $query): ?string
    {
        foreach (explode(' ', self::normalize($query)) as $word) {
            if ($word !== '' && mb_strlen($word) >= 2 && ! in_array($word, self::FILLER, true) && self::closeWords($index, $word) !== []) {
                return $word;
            }
        }

        return null;
    }

    /** Whether a query is worded as a question: it holds a question or filler word ("מה הכי טוב"). */
    public static function asks(string $query): bool
    {
        return array_intersect(explode(' ', self::normalize($query)), self::FILLER) !== [];
    }

    /** A word scores at least this to count as close to what was typed. */
    public const FLOOR = 0.45;

    /** Kept only when at least this share of the best close word's score. */
    public const RELATIVE_FLOOR = 0.8;

    /** Words that turn the next word into what the shopper does not want. */
    public const WITHOUT = ['בלי', 'ללא', 'without', 'no'];

    /** A number as a shopper writes it ("2500", "2,500", "2.5 אלף"), and the currency after it. */
    private const PRICE_NUMBER = '(\d{1,3}(?:[,.]\d{3})+|\d+(?:\.\d+)?)\s*(אלף|k)?';

    private const PRICE_CURRENCY = '(?:\s*(?:ש["״׳\'`]?ח|שקלים|שקל|₪|nis|ils))?';

    /**
     * "טבעת יהלום בזהב עד 2500 ש״ח": the words to search, and the price range the shopper asked for.
     * Understood: עד / מתחת ל / פחות מ / לא יותר מ / under / up to (a top), מעל / יותר מ / לפחות /
     * over / above (a bottom), בין X ל Y / מ X עד Y / X-Y (both), and a bare amount with its currency
     * as a top. The amounts are taken out of the words.
     *
     * @return array{0: string, 1: float|null, 2: float|null} the words left, the lowest price, the highest
     */
    public static function priceRange(string $query): array
    {
        // "עד 1500." or "עד 1500?": the mark that ends a sentence does not hide the amount before it.
        $text = ' '.preg_replace('/[.,!?;:]+(?=\s|$)/u', ' ', $query).' ';
        $num = self::PRICE_NUMBER;
        $cur = self::PRICE_CURRENCY;
        $min = null;
        $max = null;
        $amount = function (array $m, int $at): float {
            $digits = (string) preg_replace('/[,.](?=\d{3}(\D|$))/u', '', $m[$at]);

            return (float) $digits * (($m[$at + 1] ?? '') !== '' ? 1000 : 1);
        };

        $patterns = [
            // between and from-to: both ends
            '/\s(?:בין|between)\s*'.$num.$cur.'\s*(?:ל-?|and|to|עד|-|–)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            '/\s(?:מ-?|מ־|from)\s*'.$num.$cur.'\s*(?:עד|to|-|–)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            '/\s'.$num.$cur.'\s*(?:-|–)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            // a top
            '/\s(?:עד|מתחת\s*ל-?|מתחת|פחות\s*מ-?|לא\s+יותר\s+מ-?|under|below|up\s+to|max)\s*'.$num.$cur.'(?=\s)/iu' => 'max',
            // a bottom
            '/\s(?:מעל|יותר\s*מ-?|לפחות|over|above|min)\s*'.$num.$cur.'(?=\s)/iu' => 'min',
        ];

        foreach ($patterns as $pattern => $kind) {
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }

            // A bare "14-18" with no currency is a size or a karat, not a price.
            if ($kind === 'both' && ! preg_match('/ש|₪|nis|ils|בין|between|מ|from/iu', $m[0]) && min($amount($m, 1), $amount($m, 3)) < 100) {
                continue;
            }

            if ($kind === 'both') {
                [$min, $max] = [min($amount($m, 1), $amount($m, 3)), max($amount($m, 1), $amount($m, 3))];
            } elseif ($kind === 'max') {
                $max = $amount($m, 1);
            } else {
                $min = $amount($m, 1);
            }

            $text = str_replace($m[0], ' ', $text);
        }

        // A bare amount with its currency ("טבעת 2500 ש״ח"): a budget, so a top.
        if ($min === null && $max === null && preg_match('/\s'.$num.'\s*(?:ש["״׳\'`]?ח|שקלים|שקל|₪|nis|ils)(?=\s)/iu', $text, $m) === 1) {
            $max = $amount($m, 1);
            $text = str_replace($m[0], ' ', $text);
        }

        return [trim((string) preg_replace('/\s+/u', ' ', $text)), $min, $max];
    }

    /**
     * The phrases of a query that are actions, not words (see resources/query-actions.json):
     * "טבעת יהלום הכי זולה במבצע" is "טבעת יהלום", sorted by price, on sale only. The same in the
     * browser (readActions).
     *
     * @param  list<array<string, mixed>>  $registry
     * @return array{0: string, 1: array{ids: list<string>, sort: string|null, dir: string|null, sale: bool, stock: bool, near: float|null, min: float|null, max: float|null}}
     */
    public static function actions(string $query, array $registry): array
    {
        $text = ' '.preg_replace('/[.,!?;:]+(?=\s|$)/u', ' ', mb_strtolower($query)).' ';
        $found = ['ids' => [], 'sort' => null, 'dir' => null, 'sale' => false, 'stock' => false, 'near' => null, 'min' => null, 'max' => null];

        foreach ($registry as $entry) {
            foreach ((array) ($entry['patterns'] ?? []) as $pattern) {
                if (@preg_match('~(^|\s)(?:'.$pattern.')(?=\s|$)~iu', $text, $m) !== 1) {
                    continue;
                }
                $text = self::replaceFirst($text, $m[0], $m[1].' ');
                $do = (array) ($entry['do'] ?? []);
                if (isset($do['sort']) && $found['sort'] === null) {
                    [$found['sort'], $found['dir']] = [(string) $do['sort'], (string) ($do['dir'] ?? 'asc')];
                }
                if (($do['filter'] ?? null) === 'sale') {
                    $found['sale'] = true;
                }
                if (($do['filter'] ?? null) === 'stock') {
                    $found['stock'] = true;
                }
                foreach (['near', 'min', 'max'] as $bound) {
                    if (isset($do[$bound]) && isset($m[2])) {
                        $found[$bound] = (float) str_replace(',', '.', $m[2]);
                    }
                }
                $found['ids'][] = (string) $entry['id'];
                break;
            }
        }

        return [trim((string) preg_replace('/\s+/u', ' ', $text)), $found];
    }

    /**
     * Products as the actions ask: on sale or in stock only, near a size, sorted by price, size or
     * newness (what has the value first, in stock before out of stock, then the rest in their
     * order). A product's size and date are its index record's "z" and "d"; with no size, a size
     * action does nothing. The same in the browser (applyActions).
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array{sort: string|null, dir: string|null, sale: bool, stock: bool, near: float|null, min?: float|null, max?: float|null}  $actions
     * @return list<array<string, mixed>>
     */
    public static function applyActions(array $records, array $actions): array
    {
        if ($actions['sale']) {
            $records = array_values(array_filter($records, fn (array $r): bool => ($r['sl'] ?? 0) === 1));
        }
        if ($actions['stock']) {
            $records = array_values(array_filter($records, fn (array $r): bool => ($r['s'] ?? 0) === 1));
        }
        if ($actions['near'] !== null && array_filter($records, fn (array $r): bool => isset($r['z'])) !== []) {
            $near = $actions['near'];
            $records = array_values(array_filter($records, fn (array $r): bool => isset($r['z']) && abs((float) $r['z'] - $near) <= max($near * 0.15, 0.0001)));
        }
        foreach (['min' => 1, 'max' => -1] as $bound => $side) {
            $limit = $actions[$bound] ?? null;
            if ($limit !== null && array_filter($records, fn (array $r): bool => isset($r['z'])) !== []) {
                $records = array_values(array_filter($records, fn (array $r): bool => isset($r['z']) && $side * ((float) $r['z'] - $limit) >= 0));
            }
        }
        $field = ['price' => 'pr', 'size' => 'z', 'new' => 'd'][$actions['sort'] ?? ''] ?? null;
        if ($field === null) {
            return $records;
        }
        $sign = $actions['dir'] === 'desc' ? -1 : 1;
        $at = array_flip(array_map(fn (array $r): string => (string) $r['id'], $records));
        usort($records, function (array $a, array $b) use ($field, $sign, $at): int {
            $hasA = is_numeric($a[$field] ?? null);
            $hasB = is_numeric($b[$field] ?? null);
            $inA = ($a['s'] ?? 0) === 1;
            $inB = ($b['s'] ?? 0) === 1;

            return [$hasB, $inB] <=> [$hasA, $inA]
                ?: ($hasA && $hasB ? $sign * ((float) $a[$field] <=> (float) $b[$field]) : 0)
                ?: $at[(string) $a['id']] <=> $at[(string) $b['id']];
        });

        return $records;
    }

    /** "80 על 150", "80X150", "80×150": one size, written one way. The same in the browser (facetText). */
    public static function facetText(string $text): string
    {
        return self::normalize((string) preg_replace('/(\d)\s*(?:[x×X*✕]|על|on)\s*(\d)/u', '$1x$2', $text));
    }

    /**
     * The shop's filter values the words name ("כחול", "כחולה", "בכחול", "80x150", "זהב צהוב"),
     * longest first, one value per phrase; never the product's type. The same in the browser
     * (queryFacets).
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets  the index's
     * @return list<array{code: int, said: string, label: string, title: string}>
     */
    public static function facets(string $raw, array $facets): array
    {
        $text = ' '.self::facetText($raw).' ';
        $found = [];

        foreach ($facets as $at => $facet) {
            if (($facet['k'] ?? '') === 'type') {
                continue;
            }

            foreach ((array) ($facet['v'] ?? []) as $i => $value) {
                $name = self::facetText((string) ($value['l'] ?? ''));

                if (mb_strlen($name) < 3 && preg_match('/\d/', $name) !== 1) {
                    continue;
                }

                $hebrew = preg_match('/[\x{0590}-\x{05FF}]$/u', $name) === 1;
                if (preg_match('/\s[ובהלמ]?('.preg_quote($name, '/').($hebrew ? '(?:ה|ימ|ות)?' : '').')(?=\s)/u', $text, $m) === 1) {
                    $found[] = ['code' => $at * 1000 + $i, 'said' => $m[1], 'label' => (string) $value['l'], 'title' => (string) ($facet['l'] ?? '')];
                }
            }
        }

        usort($found, fn (array $a, array $b): int => [mb_strlen($b['said']), $a['code']] <=> [mb_strlen($a['said']), $b['code']]);
        $taken = [];

        return array_values(array_filter($found, function (array $named) use (&$taken): bool {
            foreach ($taken as $word) {
                if (str_contains($word, $named['said'])) {
                    return false;
                }
            }
            $taken[] = $named['said'];

            return true;
        }));
    }

    /** The words as typed, less the ones that read as $said ("80 על 150" for "80x150"); null when not found. The same in the browser. */
    public static function withoutSaid(string $raw, string $said): ?string
    {
        $tokens = preg_split('/\s+/u', $raw) ?: [];

        for ($size = 1; $size <= 4; $size++) {
            for ($i = 0; $i + $size <= count($tokens); $i++) {
                $piece = self::facetText(implode(' ', array_slice($tokens, $i, $size)));
                if ($piece === $said || (string) preg_replace('/^[ובהלמ]/u', '', $piece) === $said) {
                    return trim((string) preg_replace('/\s+/u', ' ', implode(' ', [...array_slice($tokens, 0, $i), ...array_slice($tokens, $i + $size)])));
                }
            }
        }

        return null;
    }

    /**
     * "שטיח כחול 80 על 150": the words that name filter values are searched as filters, not as
     * words, when the products the other words find have those values; otherwise they stay words
     * (a shop whose blue rugs are not marked blue still finds them by the word). The same in the
     * browser (lookup).
     *
     * @param  array<string, mixed>  $index  as LoadedIndex keeps it
     */
    public static function withoutFacetWords(array $index, string $query): string
    {
        $named = self::facets($query, (array) ($index['facets'] ?? []));

        if ($named === []) {
            return $query;
        }

        $stripped = $query;
        foreach ($named as $value) {
            $stripped = self::withoutSaid($stripped, $value['said']) ?? $stripped;
        }

        if ($stripped === '' || $stripped === $query) {
            return $query;
        }

        $codes = array_column($named, 'code');
        foreach (self::search($index['engine'], $stripped) as $hit) {
            $record = $index['records'][$hit['id']] ?? null;
            if (($record['t'] ?? null) === 'product' && array_diff($codes, (array) ($record['f'] ?? [])) === []) {
                return $stripped;
            }
        }

        return $query;
    }

    /**
     * "טבעת או עגילים", "או טבעת או עגילים": each side searched on its own, its results added to
     * the other's. One element when the query names no "או". The same in the browser (either).
     *
     * @return list<string>
     */
    public static function either(string $query): array
    {
        $text = trim((string) preg_replace('/^או\s+/u', '', trim($query)));
        $sides = array_values(array_filter(array_map('trim', preg_split('/\s+או\s+/u', $text) ?: []), fn (string $side): bool => $side !== ''));

        return count($sides) > 1 ? $sides : [$query];
    }

    private static function replaceFirst(string $text, string $find, string $with): string
    {
        $at = strpos($text, $find);

        return $at === false ? $text : substr_replace($text, $with, $at, strlen($find));
    }

    /** Whether a price is inside the range; a product with no price is outside any range. */
    public static function inRange(mixed $price, ?float $min, ?float $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        if (! is_numeric($price)) {
            return false;
        }

        return ($min === null || (float) $price >= $min) && ($max === null || (float) $price <= $max);
    }

    /**
     * "צמיד חוליות בלי יהלומים": what to look for, and the stems of what must not be there. The
     * word after בלי / ללא / without is left out of the search and keeps products that hold it away.
     *
     * @return array{0: string, 1: list<string>} the normalized query without them, and the stems to keep out
     */
    public static function without(string $query): array
    {
        $tokens = explode(' ', self::normalize($query));
        $keep = [];
        $out = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (in_array($tokens[$i], self::WITHOUT, true) && isset($tokens[$i + 1])) {
                $word = $tokens[++$i];
                $base = mb_strlen($word) > 4 ? (string) preg_replace('/(ימ|ות)$/u', '', $word) : $word;
                $out[] = mb_strlen($base) >= 2 ? $base : $word;

                continue;
            }

            if ($tokens[$i] !== '') {
                $keep[] = $tokens[$i];
            }
        }

        return [implode(' ', $keep), $out];
    }

    /** Whether a text holds any of the stems kept out (a prefix like ו or ב in front is still the word). */
    public static function holdsAny(string $text, array $stems): bool
    {
        $hay = ' '.self::normalize($text);

        foreach ($stems as $stem) {
            if (preg_match('/ [והבלמשכ]?'.preg_quote($stem, '/').'/u', $hay) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(?string $text): string
    {
        $text = mb_strtolower((string) $text, 'UTF-8');
        $text = (string) preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text);
        $text = (string) preg_replace('/[\x{05F3}\x{05F4}\'"`]/u', '', $text);
        $text = strtr($text, self::FINALS);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /** ברגים and בורג are both ברג. */
    public static function stem(string $word): string
    {
        if (! preg_match('/^[א-ת]+$/u', $word) || mb_strlen($word) < 3) {
            return $word;
        }

        $base = mb_strlen($word) > 4 ? (string) preg_replace('/(ימ|ות)$/u', '', $word) : $word;

        return mb_substr($base, 0, 1).preg_replace('/[וי]/u', '', mb_substr($base, 1));
    }

    public static function sound(string $word): string
    {
        return strtr(self::stem($word), self::ALIKE);
    }

    /** @return array<string, float> three-letter pieces of the word and of its sound, length 1 */
    public static function wordVector(string $word): array
    {
        $vector = [];
        $heard = self::sound($word);
        $sides = $heard !== $word ? [$word, '~'.$heard] : [$word];

        foreach ($sides as $side) {
            $letters = preg_split('//u', ' '.$side.' ', -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 0, $n = count($letters) - 2; $i < $n; $i++) {
                $piece = $letters[$i].$letters[$i + 1].$letters[$i + 2];
                $vector[$piece] = ($vector[$piece] ?? 0) + 1;
            }
        }

        $length = sqrt(array_sum(array_map(static fn ($w) => $w * $w, $vector))) ?: 1.0;

        return array_map(static fn ($w): float => $w / $length, $vector);
    }

    /** "ncrdv" typed on an English keyboard is מברגה. Empty when the query is not all Latin keys. */
    public static function fromKeyboard(string $query): string
    {
        if (! preg_match('/^[a-z;,. ]+$/', $query) || ! preg_match('/[a-z]/', $query)) {
            return '';
        }

        return strtr($query, self::KEYBOARD);
    }

    /**
     * @param  iterable<array{id: string, title: string, keywords?: string|null}>  $items
     * @return array{items: list<array{id: string, title: string, name: string, named: string, hay: string}>, list: list<string>, holders: array<string, list<int>>, postings: array<string, list<array{0: int, 1: float}>>}
     */
    public static function build(iterable $items): array
    {
        $records = [];
        $holders = [];
        $list = [];
        $postings = [];

        foreach ($items as $item) {
            $at = count($records);
            $name = self::normalize($item['title']);
            $hay = self::normalize($item['title'].' '.($item['keywords'] ?? ''));
            $records[] = ['id' => (string) $item['id'], 'title' => (string) $item['title'], 'name' => $name, 'named' => ' '.$name.' ', 'hay' => $hay];

            foreach (explode(' ', $hay) as $word) {
                if ($word === '') {
                    continue;
                }

                if (! isset($holders[$word])) {
                    $holders[$word] = [];
                    $list[] = (string) $word;
                }

                if (end($holders[$word]) !== $at) {
                    $holders[$word][] = $at;
                }
            }
        }

        foreach ($list as $w => $word) {
            foreach (self::wordVector($word) as $piece => $weight) {
                $postings[$piece][] = [$w, $weight];
            }
        }

        return ['items' => $records, 'list' => $list, 'holders' => $holders, 'postings' => $postings];
    }

    /**
     * Catalogue words close to one typed word, with how close (1 = the word itself).
     *
     * @return array<string, float>
     */
    public static function closeWords(array $index, string $typed): array
    {
        $found = [];
        $variants = [$typed];

        // לעץ is also checked as עץ.
        if (preg_match('/^[והבלמשכ]/u', $typed) && mb_strlen($typed) > 3) {
            $variants[] = mb_substr($typed, 1);
        }

        foreach ($variants as $position => $variant) {
            $scores = [];
            $base = self::stem($variant);
            $length = mb_strlen($variant);
            $colour = self::colorOf($variant);

            foreach (self::wordVector($variant) as $piece => $weight) {
                foreach ($index['postings'][$piece] ?? [] as [$w, $other]) {
                    $scores[$w] = ($scores[$w] ?? 0) + $weight * $other;
                }
            }

            foreach ($index['list'] as $w => $candidate) {
                $candidate = (string) $candidate;
                $score = $scores[$w] ?? 0;

                if ($candidate === $variant) {
                    $score = 1;
                } elseif ($length >= 2 && str_starts_with($candidate, $variant)) {
                    $score = max($score, 0.9 - 0.02 * (mb_strlen($candidate) - $length));
                } elseif ($length >= 3 && self::stem($candidate) === $base) {
                    $score = max($score, 0.85);
                } elseif (mb_strlen($candidate) > 3 && preg_match('/^[והבלמשכ]/u', $candidate) && mb_substr($candidate, 1) === $variant) {
                    $score = max($score, 0.85);
                }

                if ($colour !== null && self::colorOf($candidate) === $colour) {
                    $score = max($score, 0.95);
                }

                if ($position) {
                    $score *= 0.95;
                }

                if ($score >= self::FLOOR && ($found[$candidate] ?? 0) < $score) {
                    $found[$candidate] = $score;
                }
            }
        }

        $floor = $found ? max(self::FLOOR, max($found) * self::RELATIVE_FLOOR) : self::FLOOR;

        return array_filter($found, static fn ($score): bool => $score >= $floor);
    }

    /** 0 (best) to 5 when the record holds every word as typed; -1 when it does not. */
    public static function exactTier(array $record, string $query, array $tokens): int
    {
        foreach ($tokens as $token) {
            if (! str_contains($record['hay'], $token)) {
                return -1;
            }
        }

        return match (true) {
            $record['name'] === $query => 0,
            str_starts_with($record['name'], $query) => 1,
            str_contains(' '.$record['name'], ' '.$query) => 2,
            str_contains($record['name'], $query) => 3,
            default => self::allTokensStartWords($record['name'], $tokens) ? 4 : 5,
        };
    }

    /**
     * @return list<array{id: string, title: string, score: float, exact: bool, full: bool}>
     */
    public static function rank(array $index, string $query): array
    {
        $tokens = explode(' ', $query);
        $score = [];
        $hits = [];
        $found = [];
        // "כלים לגינה" is about the garden: a word that only says "things" is neither counted nor
        // asked of a record, and holding it alone finds nothing; with no other word, it is the query.
        $generic = array_filter($tokens, fn (string $t): bool => in_array($t, self::GENERIC, true));
        $generic = count($generic) === count($tokens) ? [] : $generic;
        // A word that only says what follows is a colour or a finish ("בצבע שחור") is not counted.
        $counted = count(array_filter($tokens, fn (string $t, int $at): bool => ! in_array($t, self::DESCRIBES, true) && ! isset($generic[$at]), ARRAY_FILTER_USE_BOTH)) ?: count($tokens);
        $needed = $counted <= 2 ? $counted : $counted - 1;
        // The words that must all be there for a record to hold the whole query: filler words
        // ("עם", "של") are not asked of it, nor "בצבע": "מקדחה בצבע שחור" is a black drill, and
        // a black hook "בצבע שחור" holds two of its words but not the drill.
        $required = array_keys(array_filter($tokens, fn (string $t, int $at): bool => ! in_array($t, self::FILLER, true) && ! in_array($t, self::DESCRIBES, true) && ! isset($generic[$at]), ARRAY_FILTER_USE_BOTH));
        $required = $required === [] ? array_keys($tokens) : $required;
        $matched = [];

        foreach ($tokens as $position => $token) {
            $best = [];

            foreach (self::closeWords($index, $token) as $word => $closeness) {
                foreach ($index['holders'][$word] ?? [] as $at) {
                    // A word in the name counts for more than one in the keywords.
                    $value = $closeness * (str_contains($index['items'][$at]['named'], ' '.$word.' ') ? 1 : 0.7);

                    if (($best[$at] ?? 0) < $value) {
                        $best[$at] = $value;
                    }
                }
            }

            foreach ($best as $at => $value) {
                // A generic word adds a little to the score of what holds it, never to what is found:
                // "כלים לגינה" puts the garden tools before paint that says "כלים".
                $score[$at] = ($score[$at] ?? 0) + $value * (isset($generic[$position]) ? self::GENERIC_WEIGHT : 1);
                $hits[$at] = ($hits[$at] ?? 0) + (isset($generic[$position]) ? 0 : 1);
                $matched[$at][$position] = true;
            }
        }

        foreach ($index['items'] as $at => $record) {
            $tier = self::exactTier($record, $query, $tokens);

            if ($tier < 0 && ($hits[$at] ?? 0) < $needed) {
                continue;
            }

            $found[] = [
                'id' => $record['id'],
                'title' => $record['title'],
                'score' => ($tier >= 0 ? 10 - $tier : 0) + ($score[$at] ?? 0) / count($tokens),
                'exact' => $tier >= 0,
                // Holds every word that counts, as typed or close to it: "טבעת יהלום סוליטר בכסף" in full.
                'full' => $tier >= 0 || array_diff_key(array_flip($required), $matched[$at] ?? []) === [],
            ];
        }

        // What holds the whole query before what holds part of it; then by score.
        usort($found, static fn (array $a, array $b): int => (int) $b['full'] <=> (int) $a['full'] ?: $b['score'] <=> $a['score'] ?: mb_strlen($a['title']) <=> mb_strlen($b['title']));

        return $found;
    }

    /**
     * Everything that matches, best first. A query typed on an English keyboard is read both ways
     * and the reading whose best hit scores higher wins, so "milwakee" stays English.
     *
     * @return list<array{id: string, title: string, score: float, exact: bool, full: bool}>
     */
    public static function search(array $index, ?string $raw): array
    {
        $query = self::normalize($raw);

        if ($query === '') {
            return [];
        }

        $found = self::rank($index, $query);
        $typed = self::fromKeyboard($query);

        if ($typed !== '') {
            $hebrew = self::rank($index, $typed);

            if ($hebrew && (! $found || $hebrew[0]['score'] > $found[0]['score'])) {
                $found = $hebrew;
            }
        }

        return $found;
    }

    /** A product code as compared: "DDF-485", "ddf 485" and "DDF485" are one code. */
    public static function code(?string $text): string
    {
        return str_replace(' ', '', self::normalize($text));
    }

    /**
     * The products by their code (SKU), for single(): code => ids. Codes shorter than three
     * characters are left out; "14" is a karat or a size more often than a product.
     *
     * @param  iterable<array<string, mixed>>  $items  records of the index (id, t, sku)
     * @return array<string, list<string>>
     */
    public static function codes(iterable $items): array
    {
        $codes = [];

        foreach ($items as $item) {
            $code = ($item['t'] ?? null) === 'product' ? self::code((string) ($item['sku'] ?? '')) : '';

            if (mb_strlen($code) >= 3) {
                $codes[$code][] = (string) $item['id'];
            }
        }

        return $codes;
    }

    /**
     * The one product a search is plainly for, or null: the product whose code (SKU) is what was
     * typed, or the only product that holds every word. Shown on its own, with the rest behind
     * "similar items". Not for questions or price ranges; the caller decides that.
     * let-agents-search.js has the same rule (single()); change both or neither.
     *
     * @param  array<string, list<string>>  $codes  from codes()
     * @param  list<array{id: string, full?: bool}>  $hits  from search()
     * @return array{id: string, by: string}|null by: "sku" or "words"
     */
    public static function single(array $codes, ?string $raw, array $hits): ?array
    {
        $code = self::code($raw);

        if ($code === '') {
            return null;
        }

        if (count($codes[$code] ?? []) === 1) {
            return ['id' => $codes[$code][0], 'by' => 'sku'];
        }

        $whole = array_values(array_filter($hits, fn (array $hit): bool => ($hit['full'] ?? false) && str_starts_with($hit['id'], 'p:')));

        return count($whole) === 1 ? ['id' => $whole[0]['id'], 'by' => 'words'] : null;
    }

    /**
     * Narrower searches to offer when no product holds every word of a query: "מקדחה בצבע שחור"
     * finds drills and black hooks, none both, so "מקדחה" and "בצבע שחור" are offered, each with
     * how many products hold all of it. The query without one of its words at a time (a word's
     * "בצבע" goes with it), the first words kept longest. Empty when a product holds every word
     * or fewer than two words count. let-agents-search.js has the same focus(); change both.
     *
     * @return list<array{query: string, count: int}>
     */
    public static function focus(array $index, ?string $raw, int $max = 3): array
    {
        $words = preg_split('/\s+/u', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $norm = array_map(fn (string $word): string => self::normalize($word), $words);
        $content = array_keys(array_filter($norm, fn (string $n): bool => $n !== '' && ! in_array($n, self::FILLER, true) && ! in_array($n, self::DESCRIBES, true)));

        if (count($content) < 2 || self::wholeCount($index, (string) $raw) > 0) {
            return [];
        }

        $out = [];
        foreach (array_reverse($content) as $drop) {
            $keep = [];
            foreach ($words as $i => $word) {
                if ($i !== $drop && ! ($i === $drop - 1 && in_array($norm[$i], self::DESCRIBES, true)) && $norm[$i] !== '') {
                    $keep[] = $word;
                }
            }
            $query = implode(' ', $keep);
            $count = self::wholeCount($index, $query);

            if ($count > 0 && ! in_array($query, array_column($out, 'query'), true)) {
                $out[] = ['query' => $query, 'count' => $count];
            }
        }

        return array_slice($out, 0, $max);
    }

    /** How many products hold every word of a query. */
    private static function wholeCount(array $index, string $query): int
    {
        return count(array_filter(self::search($index, $query), fn (array $hit): bool => $hit['full'] && str_starts_with($hit['id'], 'p:')));
    }

    private static function allTokensStartWords(string $name, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! str_contains(' '.$name, ' '.$token)) {
                return false;
            }
        }

        return true;
    }
}
