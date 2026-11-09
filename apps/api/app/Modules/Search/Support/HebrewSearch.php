<?php

namespace App\Modules\Search\Support;

/**
 * Typo-tolerant Hebrew search without a model: word vectors made of three-letter pieces, plus a
 * spelling stem and a sound key, compared by cosine.
 *
 * The algorithm, its thresholds and its weights come unchanged from the search that was built and
 * measured on the pilot store (docs/search-mechanism.md there). resources/search/hebrew-search-engine.js
 * runs the same algorithm in the browser for instant suggestions; the two must return the same
 * ids in the same order, so a change here is a change there, and HebrewSearchTest checks
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

    /** Whether a normalized word only wraps a question around what it is about ("מה", "טוב", "best"). */
    public static function isFiller(string $word): bool
    {
        return in_array($word, self::FILLER, true);
    }

    /**
     * A colour is one word whatever its gender and number: "מברגה שחורה" finds a screwdriver whose
     * colour is "שחור". Normalized (no final letters). Brown is left out: "חומה" is also a wall.
     */
    private const COLORS = [['שחור', 'שחורה', 'שחורימ', 'שחורות'], ['לבנ', 'לבנה', 'לבנימ', 'לבנות'], ['אדומ', 'אדומה', 'אדומימ', 'אדומות'], ['כחול', 'כחולה', 'כחולימ', 'כחולות', 'נייבי', 'תכלת'], ['ירוק', 'ירוקה', 'ירוקימ', 'ירוקות'], ['צהוב', 'צהובה', 'צהובימ', 'צהובות'], ['אפור', 'אפורה', 'אפורימ', 'אפורות'], ['כתומ', 'כתומה', 'כתומימ', 'כתומות'], ['סגול', 'סגולה', 'סגולימ', 'סגולות'], ['ורוד', 'ורודה', 'ורודימ', 'ורודות'], ['כסופ', 'כסופה', 'כסופימ', 'כסופות'], ['זהוב', 'זהובה', 'זהובימ', 'זהובות'], ['שקופ', 'שקופה', 'שקופימ', 'שקופות'], ['חומ', 'חומה', 'חומימ', 'חומות'], ['בז']];

    /** @var array<string, string>|null form => the family's first form */
    private static ?array $colorOf = null;

    public static function colorOf(string $word): ?string
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
        // a shopper's "בטריה" is the store's "סוללה" (a drill "עם בטריה", "כולל סוללות")
        ['סוללה', 'סוללות', 'בטריה', 'בטריות', 'בטרייה', 'בטרייות'],
    ];

    /** How much a generic word counts in a record's score, against 1 for a word that says what. */
    public const GENERIC_WEIGHT = 0.2;

    /** A word in the name counts 1; one of the keywords (the description, the store's fields) less. */
    public const KEYWORD_WEIGHT = 0.7;

    /**
     * A word of a tag the product was given by meaning counts between the two, by how central the tag is to
     * it: a tag it is all about (1) as much as its name, one in its background (.5) little more than a
     * passing mention. "חטופים" puts the book tagged "חטופים ושבי" at 1 before one whose blurb names them.
     */
    public const TAG_WEIGHT = 0.3;

    /** Words a shopper wraps a wish in ("אני רוצה", "תראה לי", "במחיר") and the occasion ("מתנה"): never what to search for in a sentence. */
    private const WISH = ['אני', 'אנחנו', 'רוצה', 'רוצימ', 'רוצות', 'מחפש', 'מחפשת', 'מחפשימ', 'מחפשות', 'תראה', 'תראי', 'תראו', 'הראה', 'תציג', 'תציגי', 'הצג',
        'אשמח', 'בבקשה', 'במחיר', 'מחיר', 'בערכ', 'שלי', 'שלה', 'שלו', 'עבור', 'לקנייה', 'לרכוש', 'ממליצ', 'תמליצ', 'תמליצי', 'המלצה',
        'מתנה', 'מתנות', 'למתנה', 'כמתנה', 'want', 'looking', 'show', 'me', 'please', 'price', 'gift', 'present'];

    /** Words that only say "things" ("כלים לגינה", "ציוד לגינה", "מוצרים לניקוי"), normalized. */
    private const GENERIC = ['כלימ', 'כלי', 'ציוד', 'מוצרימ', 'מוצר', 'אביזרימ', 'אביזר', 'פריטימ', 'פריט', 'דברימ', 'חפצימ', 'משהו', 'משהי', 'things', 'products', 'items', 'stuff', 'something', 'anything'];

    /**
     * What a sentence is about, in the catalogue's words: question and filler words dropped, then
     * every word no product or page holds anything close to ("ביתית", "וזולה"). "מה הכי טוב
     * למברגה ביתית וזולה" is "למברגה". Empty when nothing is left.
     */
    /**
     * Whether words say a label: each of its words as a word of them, maybe after a prefix letter or with a plural or
     * feminine ending ("טבעת זהב צהוב" says "זהב צהוב"; "זהבים" too). No sound-alikes.
     */
    public static function says(string $words, string $label): bool
    {
        $hay = ' '.self::normalize($words).' ';
        $parts = array_filter(explode(' ', self::normalize($label)), fn (string $w): bool => $w !== '');
        foreach ($parts as $w) {
            if (preg_match('/ [והבלמשכ]?'.preg_quote($w, '/').'(?:ימ|ות|ה|י|ת)? /u', $hay) !== 1) {
                return false;
            }
        }

        return $parts !== [];
    }

    /** Words of how a thing looks besides its colour: a shade. Normalized. */
    private const SHADES = ['בהיר', 'בהירה', 'בהירימ', 'בהירות', 'כהה', 'כהימ', 'כהות', 'מבריק', 'מבריקה', 'מט', 'שקופ', 'שקופה', 'light', 'dark', 'bright', 'pale', 'shiny', 'matte'];

    /**
     * Whether a search says how the thing looks: a colour, a shade, "בצבע…". Only such a search is the picture's to
     * answer alone; a genre or a topic is not seen on a cover ("מדע בדיוני": books on politics, by their covers).
     */
    public static function visual(string $query): bool
    {
        foreach (explode(' ', self::normalize($query)) as $word) {
            if ($word !== '' && (self::colorOf($word) !== null || in_array($word, self::SHADES, true) || in_array($word, self::DESCRIBES, true))) {
                return true;
            }
        }

        return false;
    }

    /** A word is known to the catalogue when one of its words is at least this close to it. */
    public const KNOWN = 0.75;

    /**
     * The words of a query the catalogue has nothing close to: "חולצה" in a jewellery store (its nearest,
     * "חוליה", at .47). Not a question or filler word, not a number, three letters or more.
     *
     * @return list<string>
     */
    public static function unknownWords(array $index, string $query): array
    {
        $out = [];

        foreach (explode(' ', self::normalize($query)) as $word) {
            if (mb_strlen($word) < 3 || preg_match('/\d/u', $word) === 1 || in_array($word, self::FILLER, true) || in_array($word, self::DESCRIBES, true) || in_array($word, self::GENERIC, true)) {
                continue;
            }

            $close = self::closeWords($index, $word);
            if (($close === [] ? 0.0 : (float) max($close)) < self::KNOWN) {
                $out[] = $word;
            }
        }

        return $out;
    }

    public static function aboutWords(array $index, string $query): string
    {
        $words = array_filter(explode(' ', self::normalize($query)), fn (string $w): bool => $w !== '' && ! in_array($w, self::FILLER, true));

        return implode(' ', array_filter($words, fn (string $w): bool => mb_strlen($w) >= 2 && self::closeWords($index, $w) !== []));
    }

    /**
     * What a sentence no product holds asks for, in the catalogue's words: "אני רוצה לקנות מתנה לאשתי טבעת יהלום" is
     * "טבעת יהלומ". Question, filler and wish words go, and so does a word that says whom it is for ("לאשתי", "לאמא")
     * when the catalogue has nothing near it. A product word the store lacks ("חולצה") stays, so the shopper is told
     * it is not here. The words as they were when nothing is left.
     */
    public static function meant(array $index, string $query): string
    {
        $kept = [];

        foreach (explode(' ', self::normalize($query)) as $word) {
            // "או" stays: "טבעת או עגילים" is searched one side at a time (either())
            if ($word === '' || ($word !== 'או' && in_array($word, self::FILLER, true)) || in_array($word, self::WISH, true)) {
                continue;
            }

            if (mb_substr($word, 0, 1) === 'ל' && mb_strlen($word) >= 3) {
                $close = self::closeWords($index, $word);
                if (($close === [] ? 0.0 : (float) max($close)) < self::KNOWN) {
                    continue;
                }
            }

            $kept[] = $word;
        }

        return $kept === [] ? self::normalize($query) : implode(' ', $kept);
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

    /** A number as a shopper writes it ("2500", "2,500", "2.5 אלף", "$1000"), and the currency after it. */
    private const PRICE_NUMBER = '(?:[$€£₪]\s*)?(\d{1,3}(?:[,.]\d{3})+|\d+(?:\.\d+)?)\s*(אלף|k)?';

    /** Shekels, and dollars, euros and pounds (Gil Travel, 28.10: "max price 1000$" was searched for "1000"). */
    private const CURRENCY = 'ש["״׳\'`]?ח|שקלים|שקל|₪|nis|ils|\$|usd|dollars?|דולר(?:ים)?|€|eur|euros?|יורו|£|gbp|ליש"ט';

    private const PRICE_CURRENCY = '(?:\s*(?:'.self::CURRENCY.'))?';

    /** A top: "עד", "under", "max price", "price up to" (the word "price" between the top and the amount, or before both). */
    private const PRICE_TOP = 'עד|מתחת\s*ל-?|מתחת|פחות\s*מ-?|לא\s+יותר\s+מ-?|under|below|up\s+to|less\s+than|no\s+more\s+than|cheaper\s+than|max(?:imum)?(?:\s+(?:price|budget))?(?:\s+of)?|(?:price|budget)\s+(?:up\s+to|under|below|max(?:imum)?|of\s+up\s+to)';

    /** A bottom: "מעל", "over", "more than", "min price". */
    private const PRICE_BOTTOM = 'מעל|יותר\s*מ-?|לפחות|over|above|more\s+than|at\s+least|min(?:imum)?(?:\s+price)?(?:\s+of)?|(?:price|budget)\s+(?:over|above|from)';

    /** "4 days" in a search, "A 10-Day Odyssey" in a title: a tour's length in days ("6 nights" is a day more). */
    private const DAYS = '(\d{1,2})\s*[-–]?\s*(?:[\p{L}\']+\s+)?(?:days?|ימים)';

    private const NIGHTS = '(\d{1,2})\s*[-–]?\s*(?:nights?|לילות)';

    /** The longest a length in days is taken to be: past it, a number is something else ("30 days return"). */
    public const MAX_DAYS = 45;

    /**
     * How many days a tour, a course or a trip lasts, as its own words say it ("A 10-Day Jewish Odyssey", "7 Day – Israel",
     * "4 Magical Days", "6 nights", "5 ימים"); null when they say none. A day's number in an itinerary ("Day 3") is not
     * a length.
     */
    public static function daysIn(string $text): ?int
    {
        foreach ([[self::DAYS, 0], [self::NIGHTS, 1]] as [$pattern, $more]) {
            if (preg_match('/(?<![\d.,:])'.$pattern.'\b/iu', $text, $m) === 1) {
                $days = (int) $m[1] + $more;

                return $days >= 1 && $days <= self::MAX_DAYS ? $days : null;
            }
        }

        return null;
    }

    /**
     * "4 days israel": the words to search, and the length asked for in days (a tour of about that many). Only where
     * the shop's offers say their length (the length filter): elsewhere "3 days" is words like any.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function days(string $query): array
    {
        $text = ' '.$query.' ';
        foreach ([[self::DAYS, 0], [self::NIGHTS, 1]] as [$pattern, $more]) {
            if (preg_match('/\s(?:(?:of|for|ל-?|של)\s+)?'.$pattern.'(?=\s)/iu', $text, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] + $more <= self::MAX_DAYS) {
                return [trim((string) preg_replace('/\s+/u', ' ', str_replace($m[0], ' ', $text))), (int) $m[1] + $more];
            }
        }

        return [trim($query), null];
    }

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
            // between and from-to: both ends. "שבין", "ובין": a prefix letter before "בין" (Rocks, 25.10: "במחיר שבין 2000
            // עד 2500" was read as "up to 2500", and "2000" was searched for as a word no product holds)
            '/\s(?:[והבלמשכ]?בין|between)\s*'.$num.$cur.'\s*(?:ל-?|and|to|עד|-|–)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            '/\s(?:מ-?|מ־|from)\s*'.$num.$cur.'\s*(?:עד|to|-|–)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            '/\s'.$num.$cur.'\s*(?:-|–|עד|to)\s*'.$num.$cur.'(?=\s)/iu' => 'both',
            // a top
            '/\s(?:'.self::PRICE_TOP.')\s*'.$num.$cur.'(?=\s)/iu' => 'max',
            // a bottom
            '/\s(?:'.self::PRICE_BOTTOM.')\s*'.$num.$cur.'(?=\s)/iu' => 'min',
        ];

        foreach ($patterns as $pattern => $kind) {
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }

            // A bare "14-18" with no currency is a size or a karat, not a price.
            if ($kind === 'both' && ! preg_match('/ש|₪|nis|ils|\$|usd|dollar|€|eur|£|בין|between|מ|from/iu', $m[0]) && min($amount($m, 1), $amount($m, 3)) < 100) {
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
        if ($min === null && $max === null && (preg_match('/\s'.$num.'\s*(?:'.self::CURRENCY.')(?=\s)/iu', $text, $m) === 1 || preg_match('/\s[$€£₪]\s*(\d{1,3}(?:[,.]\d{3})+|\d+(?:\.\d+)?)()(?=\s)/iu', $text, $m) === 1)) {
            $max = $amount($m, 1);
            $text = str_replace($m[0], ' ', $text);
        }

        // "במחיר", "בתקציב של": with the range read, the word that introduced it says nothing a product holds.
        if ($min !== null || $max !== null) {
            $text = (string) preg_replace('/\s(?:[בו]?מחיר(?:ים)?|[בו]?תקציב|[בו]?טווח(?:\s+מחירים)?|price|budget)(?:\s+של)?(?=\s)/iu', ' ', $text);
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
        $found = ['ids' => [], 'sort' => null, 'dir' => null, 'sale' => false, 'stock' => false, 'near' => null, 'within' => null, 'min' => null, 'max' => null, 'band' => null];

        foreach ($registry as $entry) {
            foreach ((array) ($entry['patterns'] ?? []) as $pattern) {
                // matched as written, its last letters final ("במבצעים", "הכי זולים"): a search arrives normalized
                // ("מבצעימ"), and a phrase ending in a final letter was never read here, only in the browser (Take Me
                // Home, 9.11: "מבצעים" was searched for as a word). The same letters at the same places: cut from the text.
                $seen = (string) preg_replace_callback('/(?<=\p{L})([כמנפצ])(?=\s|$)/u', fn (array $l): string => array_flip(self::FINALS)[$l[1]], $text);
                if (@preg_match('~(^|\s)(?:'.$pattern.')(?=\s|$)~iu', $seen, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $text = substr_replace($text, $m[1][0].' ', $m[0][1], strlen($m[0][0]));
                $m = array_map(fn ($group) => is_array($group) ? $group[0] : $group, $m);
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
                if (in_array($do['band'] ?? null, ['top', 'bottom'], true) && $found['band'] === null) {
                    $found['band'] = (string) $do['band'];
                }
                foreach (['near', 'min', 'max'] as $bound) {
                    if (isset($do[$bound]) && isset($m[2])) {
                        $found[$bound] = (float) str_replace(',', '.', $m[2]);
                        // A size one wears ("מידה 54") is that size, give or take a step; a measure ("בגודל 30") is about it.
                        $found['within'] = $bound === 'near' && isset($do['within']) ? (float) $do['within'] : $found['within'];
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
    /**
     * Where the largest (top) or smallest (bottom) third of these sizes begins. As in the browser (band).
     *
     * @param  list<mixed>  $values
     */
    public static function band(array $values, string $side): float
    {
        $sizes = array_values(array_map('floatval', array_filter($values, 'is_numeric')));
        sort($sizes);

        if ($sizes === []) {
            return $side === 'top' ? -INF : INF;
        }

        $third = max(1, (int) round(count($sizes) / 3));

        return $side === 'top' ? $sizes[count($sizes) - $third] : $sizes[$third - 1];
    }

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
            $records = array_values(array_filter($records, fn (array $r): bool => isset($r['z']) && abs((float) $r['z'] - $near) <= ($actions['within'] ?? max($near * 0.15, 0.0001))));
        }
        // "מידה גדולה": the third of the sizes found that is largest (or smallest), among what has a size.
        if (in_array($actions['band'] ?? null, ['top', 'bottom'], true) && array_filter($records, fn (array $r): bool => isset($r['z'])) !== []) {
            $cut = self::band(array_column($records, 'z'), (string) $actions['band']);
            $records = array_values(array_filter($records, fn (array $r): bool => isset($r['z']) && ($actions['band'] === 'top' ? (float) $r['z'] >= $cut : (float) $r['z'] <= $cut)));
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
     * The shop's filter values the words name, never the product's type ("שטיח" is what the words search for, not a
     * filter on it): readFacets, as the drawer reads them, with the index's words and families where it is given.
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets  the index's
     * @param  array<string, mixed>|null  $index  as LoadedIndex keeps it
     * @return list<array{code: int, said: string, label: string, title: string, at: list<int>, how: string, k: string, part: bool}>
     */
    public static function facets(string $raw, array $facets, ?array $index = null): array
    {
        return array_values(array_filter(self::readFacets($raw, $facets, self::readContext($index)), fn (array $read): bool => $read['k'] !== 'type'));
    }

    /**
     * What readFacets is told of a shop: the words its catalogue writes, its word families, how many products carry each
     * value, and the words its shoppers keep taking off as chips (LearnFromSearches), read as no value. The drawer the same.
     *
     * @param  array<string, mixed>|null  $index  as LoadedIndex keeps it
     * @return array{holders?: array<string, mixed>, families?: list<list<string>>, counts?: array<int, int>, skip?: callable}
     */
    public static function readContext(?array $index): array
    {
        if ($index === null) {
            return [];
        }
        $nochips = array_map(fn ($w): string => self::normalize((string) $w), (array) ($index['nochips'] ?? []));

        return [
            'holders' => (array) ($index['engine']['holders'] ?? []),
            'families' => (array) ($index['families'] ?? []),
            'counts' => (array) ($index['counts'] ?? []),
            'skip' => $nochips === [] ? null : fn (int $code, string $label): bool => in_array(self::normalize($label), $nochips, true),
        ];
    }

    private const READ_HOW = ['exact', 'form', 'family', 'vowels', 'sound', 'slip'];

    private const READ_SIZE_WORD = '/^(?:[ובה]?מיד[הת]|size)$/u';

    private const READ_DAYS = '/^(?:day|days|ימימ|יומ)$/u';

    private const READ_NIGHTS = '/^(?:night|nights|לילות|לילה)$/u';

    /** @var array<string, array<string, mixed>> */
    private static array $prepared = [];

    /** @var array<string, array<string, list<int>>> */
    private static array $familyMaps = [];

    /** A typed word without the one letter Hebrew puts before it ("בזהב": "זהב"), when two letters or more are left. */
    private static function readBare(string $word): ?string
    {
        return mb_strlen($word) > 2 && preg_match('/^[ובהלמ]/u', $word) === 1 && preg_match('/^[א-ת]+$/u', $word) === 1 ? mb_substr($word, 1) : null;
    }

    /** How far two words are by one letter more, less, another or two side by side swapped; more than max as max + 1. */
    private static function slips(string $a, string $b, int $max): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        if (abs(count($x) - count($y)) > $max) {
            return $max + 1;
        }
        $rows = [];
        for ($i = 0; $i <= count($x); $i++) {
            $rows[$i] = [0 => $i];
        }
        for ($j = 1; $j <= count($y); $j++) {
            $rows[0][$j] = $j;
        }
        for ($i = 1; $i <= count($x); $i++) {
            $least = $max + 1;
            for ($j = 1; $j <= count($y); $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $d = min($rows[$i - 1][$j] + 1, $rows[$i][$j - 1] + 1, $rows[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $d = min($d, $rows[$i - 2][$j - 2] + 1);
                }
                $rows[$i][$j] = $d;
                $least = min($least, $d);
            }
            if ($least > $max) {
                return $max + 1;
            }
        }

        return $rows[count($x)][count($y)];
    }

    /**
     * Each single word of the store's families with the families it is in. A family lists its forms itself: never by their
     * core, which would make one family of "מברגה" (a drill) and "מברג" (a screwdriver).
     *
     * @param  list<list<string>>  $families
     * @return array<string, list<int>>
     */
    private static function familyMap(array $families): array
    {
        $key = hash('xxh128', serialize($families));
        if (isset(self::$familyMaps[$key])) {
            return self::$familyMaps[$key];
        }
        $map = [];
        foreach (array_values($families) as $at => $family) {
            foreach ((array) $family as $member) {
                $m = self::normalize((string) $member);
                if ($m === '' || str_contains($m, ' ')) {
                    continue;
                }
                if (! in_array($at, $map[$m] ?? [], true)) {
                    $map[$m][] = $at;
                }
            }
        }
        if (count(self::$familyMaps) >= 8) {
            array_shift(self::$familyMaps);
        }

        return self::$familyMaps[$key] = $map;
    }

    /**
     * @param  array<string, list<int>>  $map
     * @param  list<string|null>  $words
     * @return list<int>
     */
    private static function familiesOf(array $map, array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            foreach ($w !== null && $w !== '' ? ($map[$w] ?? []) : [] as $at) {
                if (! in_array($at, $out, true)) {
                    $out[] = $at;
                }
            }
        }

        return $out;
    }

    /**
     * What the reader needs of a store's filters, worked out once per index: each value's words; the words that name what
     * products are (a category's or a kind's, three letters or more); the words of the values that say what a product has
     * (its metal, its colour), never guessed; and how many values of each filter hold each word.
     *
     * @return array{kinds: list<string>, kindOf: array<string, bool>, attributes: array<string, bool>, many: list<array<string, int>>, values: list<array<string, mixed>>, vocabulary: list<string>}
     */
    private static function prepareFacets(array $facets): array
    {
        $key = hash('xxh128', serialize($facets));
        if (isset(self::$prepared[$key])) {
            return self::$prepared[$key];
        }
        $kinds = [];
        $kindOf = [];
        $attributes = [];
        $many = [];
        $values = [];
        $vocabulary = [];
        foreach (array_values($facets) as $g => $facet) {
            $k = (string) ($facet['k'] ?? '');
            $grouped = $k === 'category' || str_starts_with($k, 'tag:');
            $describes = ! $grouped && $k !== 'type' && $k !== 'length';
            $counted = [];
            foreach (array_values((array) ($facet['v'] ?? [])) as $i => $value) {
                $label = (string) ($value['l'] ?? '');
                $lw = array_values(array_filter(explode(' ', self::facetText($label)), fn (string $w): bool => $w !== ''));
                $cores = [];
                foreach ($lw as $w) {
                    $vocabulary[$w] = true;
                    $cores[self::core($w)] = true;
                    if (($k === 'category' || $k === 'type') && mb_strlen($w) >= 3 && ! isset($kindOf[$w])) {
                        $kindOf[$w] = true;
                        $kinds[] = $w;
                    }
                    if ($describes && mb_strlen($w) >= 2) {
                        $attributes[self::core($w)] = true;
                    }
                }
                foreach (array_keys($cores) as $c) {
                    $counted[(string) $c] = ($counted[(string) $c] ?? 0) + 1;
                }
                $values[] = ['code' => $g * 1000 + $i, 'g' => $g, 'k' => $k, 'key' => (string) ($value['k'] ?? ''), 'label' => $label, 'title' => (string) ($facet['l'] ?? ''), 'lw' => $lw, 'grouped' => $grouped];
            }
            $many[] = $counted;
        }
        // a word of a category's name that is a value of another filter ("זהב", "צהוב" in "טבעות זהב צהוב") says how a product
        // is, not what it is: no kind
        $kinds = array_values(array_filter($kinds, fn (string $w): bool => ! isset($attributes[self::core($w)])));
        if (count(self::$prepared) >= 8) {
            array_shift(self::$prepared);
        }

        return self::$prepared[$key] = ['kinds' => $kinds, 'kindOf' => $kindOf, 'attributes' => $attributes, 'many' => $many, 'values' => $values, 'vocabulary' => array_map('strval', array_keys($vocabulary))];
    }

    /** @param  list<string>  $kinds */
    private static function namesKind(array $kinds, string $word): bool
    {
        if (mb_strlen($word) < 3) {
            return false;
        }
        $bare = self::readBare($word);

        return array_any($kinds, fn (string $w): bool => $word === $w || $bare === $w
            || ((str_starts_with($w, $word) || str_starts_with($word, $w)) && abs(mb_strlen($w) - mb_strlen($word)) <= 2));
    }

    /** @return list<string> a noun's plurals: "טבעת" "טבעות", "ספר" "ספרימ", "שמלה" "שמלות" */
    private static function pluralsOf(string $word): array
    {
        $out = [$word.'ימ', $word.'ות'];
        if (preg_match('/[הת]$/u', $word) === 1) {
            $out[] = mb_substr($word, 0, -1).'ות';
        }

        return $out;
    }

    /** Two forms of one noun: one the other's plural, or the plural's construct ("עגילי", "ספרי"). Never "מברגה" for "מברג". */
    private static function nounForms(string $a, string $b): bool
    {
        $construct = fn (string $x, string $y): bool => preg_match('/י$/u', $x) === 1 && ($x.'מ' === $y || in_array($x.'מ', self::pluralsOf($y), true));

        return in_array($b, self::pluralsOf($a), true) || in_array($a, self::pluralsOf($b), true) || $construct($a, $b) || $construct($b, $a);
    }

    /**
     * How a typed word names a word of a value: 0 the same, 1 a form of it, 2 the store's family, 3 its vowel letters
     * typed otherwise, 4 a letter that sounds the same, 5 one slip of the keyboard; -1 none. A word that names what
     * products are has a noun's forms only ("מקדחה", a drill, is no "מקדח", a bit); a word that says how one is
     * ("כחולה", "אדומים") has every ending. The last three only for a word the store does not write as typed and that
     * names no kind of product: "זהב" is never "זהוב", "מרכזית" never "מרקיזה". The same in the browser (wordNames).
     *
     * @param  array{families: array<string, list<int>>|null, kindOf: array<string, bool>, attributes: array<string, bool>, guarded: callable}  $on
     */
    private static function wordNames(string $typed, string $word, array $on): int
    {
        if ($typed === $word) {
            return 0;
        }
        $bare = self::readBare($typed);
        if ($bare === $word) {
            return 1;
        }
        if (preg_match('/\d/u', $typed) === 1 || preg_match('/\d/u', $word) === 1 || mb_strlen($typed) < 3 || mb_strlen($word) < 3) {
            return -1;
        }
        $noun = ($on['kindOf'][$word] ?? false) === true && ! isset($on['attributes'][self::core($word)]);
        $form = $noun
            ? (self::nounForms($typed, $word) || ($bare !== null && mb_strlen($bare) >= 3 && self::nounForms($bare, $word)))
            : (self::core($typed) === self::core($word) || ($bare !== null && mb_strlen($bare) >= 3 && self::core($bare) === self::core($word))
                || (mb_strlen($word) >= 4 && str_starts_with($typed, $word) && mb_strlen($typed) - mb_strlen($word) <= 2));
        if ($form) {
            return 1;
        }
        if (preg_match('/^[a-z]+$/', $typed) === 1 && preg_match('/^[a-z]+$/', $word) === 1
            && ($typed === $word.'s' || $word === $typed.'s' || $typed === $word.'es' || $word === $typed.'es')) {
            return 1;
        }
        if ($on['families'] !== null) {
            $mine = self::familiesOf($on['families'], [$typed, $bare]);
            if ($mine !== [] && array_intersect(self::familiesOf($on['families'], [$word]), $mine) !== []) {
                return 2;
            }
        }
        if (($on['guarded'])($typed)) {
            return -1;
        }
        $hebrew = preg_match('/^[א-ת]+$/u', $typed) === 1 && preg_match('/^[א-ת]+$/u', $word) === 1;
        if ($hebrew && (self::stem($typed) === self::stem($word) || self::skeleton($typed) === self::skeleton($word)
            || ($bare !== null && mb_strlen($bare) >= 3 && self::stem($bare) === self::stem($word)))) {
            return 3;
        }
        if ($hebrew && mb_strlen($word) >= 4 && self::sound($typed) === self::sound($word)) {
            return 4;
        }
        if (mb_strlen($typed) >= 5 && mb_strlen($word) >= 5) {
            $max = min(mb_strlen($typed), mb_strlen($word)) >= 8 ? 2 : 1;
            if (self::slips($typed, $word, $max) <= $max) {
                return 5;
            }
        }

        return -1;
    }

    /**
     * The best places of a value's words among the typed ones: the most words, the closest together, the closest read.
     *
     * @param  array<string, list<array{0: int, 1: int}>>  $places
     * @param  list<string>  $lw
     * @return array{at: list<int>, tiers: list<int>, ks: list<int>}
     */
    private static function placeWords(array $places, array $lw): array
    {
        $lists = array_map(fn (string $w): array => $places[$w] ?? [], $lw);
        $best = ['at' => [], 'tiers' => [], 'ks' => []];
        $spread = fn (array $at): int => $at === [] ? 0 : $at[count($at) - 1] - $at[0];
        $walk = function (int $k, array $at, array $tiers, array $ks) use (&$walk, &$best, $lists, $lw, $spread): void {
            if ($k === count($lw)) {
                if (count($at) > count($best['at']) || (count($at) === count($best['at']) && ($spread($at) < $spread($best['at'])
                    || ($spread($at) === $spread($best['at']) && array_sum($tiers) < array_sum($best['tiers']))))) {
                    $best = ['at' => $at, 'tiers' => $tiers, 'ks' => $ks];
                }

                return;
            }
            $walk($k + 1, $at, $tiers, $ks);
            foreach ($lists[$k] as [$i, $tier]) {
                $last = $at === [] ? -1 : $at[count($at) - 1];
                if ($i <= $last || ($at !== [] && $i - $last > 2)) {
                    continue;
                }
                $walk($k + 1, [...$at, $i], [...$tiers, $tier], [...$ks, $k]);
            }
        };
        $walk(0, [], [], []);

        return $best;
    }

    /**
     * "מידה 52", "size M": a value right after the word for size, however short.
     *
     * @param  list<string>  $tw
     * @param  list<string>  $lw
     * @return list<int>|null
     */
    private static function sizedAt(array $tw, array $lw): ?array
    {
        if (count($lw) !== 1) {
            return null;
        }
        for ($i = 1; $i < count($tw); $i++) {
            if ($tw[$i] === $lw[0] && preg_match(self::READ_SIZE_WORD, $tw[$i - 1]) === 1) {
                return [$i - 1, $i];
            }
        }

        return null;
    }

    /**
     * A date the value's name holds ("7 אוקטובר", as normalize writes every date), said in the words.
     *
     * @param  list<string>  $tw
     * @param  list<string>  $lw
     * @return list<int>|null
     */
    private static function datedAt(array $tw, array $lw): ?array
    {
        for ($k = 0; $k + 1 < count($lw); $k++) {
            if (preg_match('/^\d{1,2}$/', $lw[$k]) === 1 && isset(self::MONTHS[$lw[$k + 1]])) {
                for ($i = 0; $i + 1 < count($tw); $i++) {
                    if ($tw[$i] === $lw[$k] && $tw[$i + 1] === $lw[$k + 1]) {
                        return [$i, $i + 1];
                    }
                }
            }
        }

        return null;
    }

    /**
     * "10 days", "a 10-day tour", "6 nights" (a day more): how many days, and where they were said.
     *
     * @param  list<string>  $tw
     * @return array{days: int, at: list<int>}|null
     */
    private static function daysAt(array $tw): ?array
    {
        for ($i = 0; $i < count($tw); $i++) {
            if (preg_match('/^\d{1,2}$/', $tw[$i]) !== 1) {
                continue;
            }
            for ($j = $i + 1; $j <= $i + 2 && $j < count($tw); $j++) {
                if (preg_match(self::READ_DAYS, $tw[$j]) === 1) {
                    return ['days' => (int) $tw[$i], 'at' => [$i, $j]];
                }
                if (preg_match(self::READ_NIGHTS, $tw[$j]) === 1) {
                    return ['days' => (int) $tw[$i] + 1, 'at' => [$i, $j]];
                }
            }
        }

        return null;
    }

    /** Whether a length value ("10-14", "15+", "1") holds a number of days. */
    private static function lengthHolds(string $key, int $days): bool
    {
        if (preg_match('/^(\d+)(?:-(\d+)|(\+))?$/', $key, $m) !== 1) {
            return false;
        }
        $from = (int) $m[1];
        $to = ($m[3] ?? '') !== '' ? PHP_INT_MAX : (($m[2] ?? '') !== '' ? (int) $m[2] : $from);

        return $days >= $from && $days <= $to;
    }

    /**
     * The store's filter values the words name. A typed word names a word of a value as itself, a form of it, the store's
     * family of it, or (for a word the store does not write) its vowel letters, sound or one slip otherwise (wordNames).
     * A name of several words is read where its words come in its order, at most one other word between two of them: "זהב
     * צהב" is "זהב צהוב". A category or a tag is read only from a name of two words or more: whole, or part of it where each
     * word left out says what the filter's values all are ("israel" is "Israel Tours") or is the one left of three or more,
     * and is never a value of another filter ("עגילי זהב" is no "עגילים זהב צהוב"). A length is read from "10 days". Each
     * typed word names one value: the one that covers the most words, then the closest reading, then the one most products
     * carry. The same value name in several filters is the one most products carry. Within one filter a part gives way to a
     * whole, and several parts to none. The same in the browser (readFacets): change both.
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets
     * @param  array{holders?: array<string, mixed>, families?: list<list<string>>, counts?: array<int, int>, skip?: callable|null}  $ctx
     * @return list<array{code: int, at: list<int>, said: string, label: string, title: string, k: string, how: string, part: bool}>
     */
    public static function readFacets(string $raw, array $facets, array $ctx = []): array
    {
        $tw = array_values(array_filter(explode(' ', self::facetText($raw)), fn (string $w): bool => $w !== ''));
        if ($tw === [] || $facets === []) {
            return [];
        }
        $prepared = self::prepareFacets($facets);
        $holders = isset($ctx['holders']) && $ctx['holders'] !== [] ? $ctx['holders'] : null;
        $on = [
            'families' => ($ctx['families'] ?? []) !== [] ? self::familyMap($ctx['families']) : null,
            'kindOf' => $prepared['kindOf'],
            'attributes' => $prepared['attributes'],
            // a word the store writes, one that names a kind, or a colour in any of its forms ("זהובה" is golden, not gold) is
            // that word: never read as another that looks like it
            'guarded' => fn (string $word): bool => ($holders !== null && self::heldWhole($holders, $word)) || self::namesKind($prepared['kinds'], $word)
                || self::colorOf($word) !== null || self::colorOf(self::readBare($word) ?? '') !== null,
        ];
        // where each word of the store's values is typed, and how closely: each pair weighed once
        $places = [];
        foreach ($prepared['vocabulary'] as $w) {
            $out = [];
            foreach ($tw as $i => $x) {
                $tier = self::wordNames($x, $w, $on);
                if ($tier >= 0) {
                    $out[] = [$i, $tier];
                }
            }
            if ($out !== []) {
                $places[$w] = $out;
            }
        }
        $counts = (array) ($ctx['counts'] ?? []);
        $count = fn (int $code): int => (int) ($counts[$code] ?? 0);
        $skip = $ctx['skip'] ?? null;
        $days = self::daysAt($tw);
        $other = array_any($tw, fn (string $w): bool => preg_match(self::READ_SIZE_WORD, $w) === 1 || isset(self::MONTHS[$w]));
        $found = [];
        foreach ($prepared['values'] as $value) {
            $lw = $value['lw'];
            if (preg_match('/^(?:אחר|other)$/u', mb_strtolower(trim($value['label']))) === 1 || $lw === [] || ($skip !== null && $skip($value['code'], $value['label']))) {
                continue;
            }
            $read = null;
            if ($value['k'] === 'length') {
                $read = $days !== null && self::lengthHolds($value['key'], $days['days']) ? ['at' => $days['at'], 'tiers' => [0], 'part' => false] : null;
            } elseif (mb_strlen(implode('', $lw)) >= 2 && array_any($lw, fn (string $w): bool => isset($places[$w]))) {
                $placed = self::placeWords($places, $lw);
                $n = count($placed['at']);
                $of = count($lw);
                // a word of the name not typed: one that says what the filter's values all are ("Tours"), or the one left of
                // three or more; never one of another filter (a colour, a metal), which the shopper did not ask
                $guessed = true;
                foreach ($lw as $k => $w) {
                    if (! in_array($k, $placed['ks'], true) && (isset($prepared['attributes'][self::core($w)])
                        || ! (($prepared['many'][$value['g']][self::core($w)] ?? 0) >= 3 || ($of >= 3 && $n >= 2 && $n >= $of - 1)))) {
                        $guessed = false;
                    }
                }
                if (! $value['grouped'] && $n === $of) {
                    // "מידה 54": the word for size goes with the size it names
                    $read = $of === 1 && $placed['at'][0] > 0 && preg_match(self::READ_SIZE_WORD, $tw[$placed['at'][0] - 1]) === 1
                        ? ['at' => [$placed['at'][0] - 1, $placed['at'][0]], 'tiers' => [0, $placed['tiers'][0]], 'part' => false]
                        : ['at' => $placed['at'], 'tiers' => $placed['tiers'], 'part' => false];
                } elseif ($value['grouped'] && $of >= 2 && $n >= 1 && ($n === $of || $guessed)) {
                    $read = ['at' => $placed['at'], 'tiers' => $placed['tiers'], 'part' => $n < $of];
                }
            }
            if ($read === null && $other) {
                $at = self::sizedAt($tw, $lw) ?? self::datedAt($tw, $lw);
                $read = $at === null ? null : ['at' => $at, 'tiers' => [0], 'part' => false];
            }
            if ($read !== null) {
                $found[] = ['code' => $value['code'], 'g' => $value['g'], 'label' => $value['label'], 'title' => $value['title'], 'k' => $value['k']] + $read;
            }
        }

        // one value name in several filters ("שחור" a colour and a coating): the one most products carry
        $byName = [];
        foreach ($found as $at => $r) {
            $name = self::normalize($r['label']);
            $was = $byName[$name] ?? null;
            if ($was === null || $count($r['code']) > $count($found[$was]['code']) || ($count($r['code']) === $count($found[$was]['code']) && $r['code'] < $found[$was]['code'])) {
                $byName[$name] = $at;
            }
        }
        $found = array_values(array_filter($found, fn (array $r, int $at): bool => $byName[self::normalize($r['label'])] === $at, ARRAY_FILTER_USE_BOTH));

        // within one filter a part gives way to a whole, and several parts to none
        $parts = [];
        $wholes = [];
        foreach ($found as $r) {
            if ($r['part']) {
                $parts[$r['g']] = ($parts[$r['g']] ?? 0) + 1;
            } else {
                $wholes[$r['g']] = ($wholes[$r['g']] ?? 0) + 1;
            }
        }
        $found = array_values(array_filter($found, fn (array $r): bool => ! $r['part'] || (! isset($wholes[$r['g']]) && ($parts[$r['g']] ?? 0) === 1)));

        usort($found, fn (array $a, array $b): int => count($b['at']) <=> count($a['at']) ?: array_sum($a['tiers']) <=> array_sum($b['tiers'])
            ?: $count($b['code']) <=> $count($a['code']) ?: $a['code'] <=> $b['code']);
        $taken = [];
        $out = [];
        foreach ($found as $r) {
            if (array_intersect($r['at'], $taken) !== []) {
                continue;
            }
            array_push($taken, ...$r['at']);
            $out[] = ['code' => $r['code'], 'at' => $r['at'], 'said' => implode(' ', array_map(fn (int $at): string => $tw[$at], $r['at'])), 'label' => $r['label'],
                'title' => $r['title'], 'k' => $r['k'], 'how' => self::READ_HOW[max($r['tiers'])], 'part' => $r['part']];
        }
        usort($out, fn (array $a, array $b): int => $a['at'][0] <=> $b['at'][0] ?: $a['code'] <=> $b['code']);

        return $out;
    }

    /** @return list<string> the words facetText reads: the places readFacets gives are places in these */
    public static function facetWords(string $raw): array
    {
        return array_values(array_filter(explode(' ', self::facetText($raw)), fn (string $w): bool => $w !== ''));
    }

    /**
     * The typed words less those at the places readFacets gave, as they were typed ("טבעת יהלום", not "טבעת יהלומ"); where
     * the typed words and the words read differ in number ("80 על 150" is one size), in the words read. The same in the
     * browser (withoutAt).
     *
     * @param  list<int>  $at
     */
    public static function withoutAt(string $raw, array $at): string
    {
        $words = self::facetWords($raw);
        $typed = array_values(array_filter(preg_split('/\s+/u', trim($raw)) ?: [], fn (string $w): bool => $w !== ''));
        $of = [];
        foreach ($typed as $j => $w) {
            foreach (self::facetWords($w) as $piece) {
                $of[] = $j;
            }
        }
        if (count($of) !== count($words)) {
            return implode(' ', array_values(array_filter($words, fn (string $w, int $i): bool => ! in_array($i, $at, true), ARRAY_FILTER_USE_BOTH)));
        }
        $gone = array_map(fn (int $i): int => $of[$i], $at);

        return implode(' ', array_values(array_filter($typed, fn (string $w, int $j): bool => ! in_array($j, $gone, true), ARRAY_FILTER_USE_BOTH)));
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
        $named = self::facets($query, (array) ($index['facets'] ?? []), $index);

        if ($named === []) {
            return $query;
        }

        // the words the values were read from, wherever they were typed ("זהב עם צהוב", "זהב צהב")
        $stripped = self::withoutAt($query, array_merge(...array_column($named, 'at')));

        // nothing left but words that join the rest ("עם", "של"): as nothing left. "טבעות עם יהלום" with "טבעות" and
        // "יהלום" read as filters searched "עם" and found the 27 rings whose words say it, of 973 (Rocks, 26.10)
        $left = array_filter(explode(' ', self::normalize($stripped)), fn (string $w): bool => $w !== '' && ! self::isFiller($w));
        if ($left === []) {
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
        $text = self::dates(strtr($text, self::FINALS));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /** The months, as the day before them is written in digits. */
    private const MONTHS = ['ינואר' => 1, 'פברואר' => 2, 'מרצ' => 3, 'מרס' => 3, 'אפריל' => 4, 'מאי' => 5, 'יוני' => 6, 'יולי' => 7, 'אוגוסט' => 8, 'ספטמבר' => 9, 'אוקטובר' => 10, 'נובמבר' => 11, 'דצמבר' => 12];

    /** A day said in words before a month: its ordinal and its number, as normalized (no final letters). */
    private const DAY_WORDS = ['ראשונ' => 1, 'אחד' => 1, 'שני' => 2, 'שניימ' => 2, 'שנימ' => 2, 'שלישי' => 3, 'שלושה' => 3, 'רביעי' => 4, 'ארבעה' => 4, 'חמישי' => 5, 'חמישה' => 5,
        'שישי' => 6, 'שישה' => 6, 'שביעי' => 7, 'שבעה' => 7, 'שמיני' => 8, 'שמונה' => 8, 'תשיעי' => 9, 'תשעה' => 9, 'עשירי' => 10, 'עשרה' => 10,
        // and as a date is said ("השבע באוקטובר"): the number in its feminine form
        'אחת' => 1, 'שתיימ' => 2, 'שתי' => 2, 'שלוש' => 3, 'ארבע' => 4, 'חמש' => 5, 'שש' => 6, 'שבע' => 7, 'תשע' => 9, 'עשר' => 10];

    /** What a number before a point is not a date in front of: a measure ("4.5 ממ"), a size, a product of sizes. */
    private const NOT_A_DATE = '(?!\s*(?:ממ|סמ|מטר|מל|ליטר|קג|גרמ|גר|אינצ|מ|mm|cm|m|kg|g|ml|l|in|x|×|\*)(?:\s|$))';

    /**
     * A date is one thing however it is written: "7.10", "7/10", "ה-7 באוקטובר", "7 באוקטובר", "השביעי באוקטובר" and
     * "שבעה באוקטובר" are all "7 אוקטובר", so a bookshop's "גבורה ותקומה אחרי 7.10" is found by "השביעי לאוקטובר"
     * (Sela Meir, 28.10: only the product whose picture read "שביעי באוקטובר" held both words, and it stood alone). A
     * number before a point followed by a measure ("4.5 ממ", "10.5 cm") is a size, not a date. Same in the browser
     * (hebrew-search-engine.js dates()); change both.
     */
    public static function dates(string $text): string
    {
        $months = implode('|', array_keys(self::MONTHS));
        $byNumber = array_flip(self::MONTHS);
        $days = implode('|', array_keys(self::DAY_WORDS));
        // "7.10", "7/10", "7.10.23", "7.10.2023": day 1–31, month 1–12, not a measure, not part of a longer number
        $text = (string) preg_replace_callback('~(^|[^\p{L}\p{N}.])(\d{1,2})[./](\d{1,2})(?:[./](\d{2,4}))?(?![\d.])'.self::NOT_A_DATE.'~u', function (array $m) use ($byNumber): string {
            $day = (int) $m[2];
            $month = (int) $m[3];

            return $day >= 1 && $day <= 31 && isset($byNumber[$month]) ? $m[1].$day.' '.$byNumber[$month].(isset($m[4]) && $m[4] !== '' ? ' '.$m[4] : '') : $m[0];
        }, $text);

        // "ה-7 באוקטובר", "7 באוקטובר", "השביעי באוקטובר", "שבעה לאוקטובר": the day in digits, the month bare
        return (string) preg_replace_callback('~(^|[^\p{L}\p{N}])ה?-?(?:(\d{1,2})|('.$days.'))\s+[בל]?('.$months.')(?![\p{L}\p{N}])~u', function (array $m): string {
            $day = $m[2] !== '' ? (int) $m[2] : self::DAY_WORDS[$m[3]];

            return $day >= 1 && $day <= 31 ? $m[1].$day.' '.$m[4] : $m[0];
        }, $text);
    }

    /**
     * Normalized words written as Hebrew writes them: each final letter back at the end of its word ("ללישונ" is
     * "ללישון", "קרשימ" "קרשים"). A Hebrew word never ends in the plain form, so this is exact; for what reads words
     * as words (a vector of the meaning), never for matching, which compares the plain forms.
     */
    public static function withFinals(string $text): string
    {
        return (string) preg_replace_callback('/([כמנפצ])(?=$|[^\p{L}\p{N}])/u', fn (array $m): string => array_flip(self::FINALS)[$m[1]], $text);
    }

    /**
     * What a Hebrew title says the product is, as a stem: the word it opens with ("תיק גב Old Skool" is "תק",
     * "נעלי סניקרס" and "נעליים" both "נעל"); '' when it opens with no Hebrew word (an English title opens with a model).
     */
    public static function head(string $title): string
    {
        $first = explode(' ', self::normalize($title), 2)[0];

        return preg_match('/^[א-ת]{2,}$/u', $first) === 1 ? self::stem($first) : '';
    }

    /**
     * A word's consonants: its first letter, then the rest without the letters that stand for vowels (א ו י, and a last ה; a ה within is a sound: "צהוב"), as Hebrew
     * typed fast drops, adds or swaps them ("ראישי", "ריישי": רש). The same in the browser (skeleton).
     */
    public static function skeleton(string $word): string
    {
        if (preg_match('/^[א-ת]+$/u', $word) !== 1) {
            return $word;
        }

        return mb_substr($word, 0, 1).preg_replace(['/[אוי]/u', '/ה$/u'], '', mb_substr($word, 1));
    }

    /** ברגים and בורג are both ברג. */
    public static function stem(string $word): string
    {
        if (! preg_match('/^[א-ת]+$/u', $word) || mb_strlen($word) < 3) {
            return $word;
        }

        $base = mb_strlen($word) > 4 ? (string) preg_replace('/(ימ|ות)$/u', '', $word) : $word;
        // A noun and its construct form are one word ("מלחמה", "מלחמת"), and an ending written with א ("קופסא", "מקיטא").
        $base = mb_strlen($base) >= 5 ? (string) preg_replace('/[התא]$/u', '', $base) : $base;

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
            $tagged = [];
            // [[phrase, weight], …]: a list, not a map, so a phrase that is a number keeps its place in the browser too
            foreach ((array) ($item['tags'] ?? []) as [$phrase, $weight]) {
                foreach (explode(' ', self::normalize((string) $phrase)) as $word) {
                    if ($word !== '' && ($tagged[$word] ?? 0) < (float) $weight) {
                        $tagged[$word] = (float) $weight;
                    }
                }
            }
            $hay = trim(self::normalize($item['title'].' '.($item['keywords'] ?? '')).' '.implode(' ', array_keys($tagged)));
            $records[] = ['id' => (string) $item['id'], 'title' => (string) $item['title'], 'name' => $name, 'named' => ' '.$name.' ', 'hay' => $hay, 'tagged' => $tagged];

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
            // A number is never a typo of another: "53077" is not "53076", "54" is not "45".
            $digits = (string) preg_replace('/\D+/u', '', $variant);

            foreach (self::wordVector($variant) as $piece => $weight) {
                foreach ($index['postings'][$piece] ?? [] as [$w, $other]) {
                    $scores[$w] = ($scores[$w] ?? 0) + $weight * $other;
                }
            }

            foreach ($index['list'] as $w => $candidate) {
                $candidate = (string) $candidate;
                $score = $scores[$w] ?? 0;

                // "שבע" is "7" and "7" is "שבע": a number as a word or as digits.
                if ((self::NUMBER_WORDS[$variant] ?? null) === $candidate || (self::NUMBER_WORDS[$candidate] ?? null) === $variant) {
                    $found[$candidate] = max($found[$candidate] ?? 0, $position ? 0.9 : 0.95);

                    continue;
                }

                if ($digits !== '' && $candidate !== $variant && ! str_starts_with($candidate, $variant) && (string) preg_replace('/\D+/u', '', $candidate) !== $digits) {
                    continue;
                }

                if ($candidate === $variant) {
                    $score = 1;
                    // "מק" while typing is "מקדחה"; a word the catalogue has as typed is that word, never the start of a longer
                    // one: "לב" is a heart, never "לבן"; "שבי" is captivity, never "שביל" (Sela, 26.10)
                } elseif ($length >= 2 && str_starts_with($candidate, $variant) && ! self::heldWhole($index['holders'], $variant)) {
                    $score = max($score, 0.9 - 0.02 * (mb_strlen($candidate) - $length));
                } elseif ($length >= 3 && self::stem($candidate) === $base) {
                    $score = max($score, 0.85);
                } elseif (mb_strlen($candidate) > 3 && preg_match('/^[והבלמשכ]/u', $candidate) && mb_substr($candidate, 1) === $variant) {
                    $score = max($score, 0.85);
                }
                // the letters of its sounds the same, its vowel letters typed otherwise: "ראישי" is "ריישי", "צגה" is "צאגה"; never
                // a word the store has as typed ("ראש" is a head), the nearer in length first ("ריישי" before "ראש" for "ראישי")
                if ($length >= 3 && $candidate !== $variant && abs(mb_strlen($candidate) - $length) <= 2 && ! isset($index['holders'][$variant])
                    && mb_strlen(self::skeleton($variant)) >= 2 && self::skeleton($candidate) === self::skeleton($variant)) {
                    $score = max($score, 0.87 - 0.02 * abs(mb_strlen($candidate) - $length));
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

        // A word the catalogue holds plainly (as typed, or a form of it: "עגילי" of "עגילים") is that word: another one that
        // only looks like it with its ו and י dropped is not ("עגול": Rocks, 25.10, "עגילי זהב" found rings cut round). A slip
        // nothing plainly holds ("לבין") is still read as the word it is close to.
        if (array_any(array_keys($found), fn ($candidate): bool => self::formOf((string) $candidate, $variants))) {
            $found = array_filter($found, static fn ($score, $candidate): bool => self::formOf((string) $candidate, $variants) || ! self::vowelsApart((string) $candidate, $variants), ARRAY_FILTER_USE_BOTH);
        }

        $floor = $found ? max(self::FLOOR, max($found) * self::RELATIVE_FLOOR) : self::FLOOR;

        return array_filter($found, static fn ($score): bool => $score >= $floor);
    }

    /** A word's endings, for telling its forms from another word: "עגילימ", "עגילי", "עגיל" are all "עגיל". */
    private static function core(string $word): string
    {
        return mb_strlen($word) > 3 ? (string) preg_replace('/(?:ימ|ות|ה|ת|י)$/u', '', $word) : $word;
    }

    /**
     * Whether a catalogue word is a form of one typed: the same word, the typed one at its start ("עגילימ" for "עגילי"),
     * or one is the other's core with an ending ("טבעת" for "טבעות").
     *
     * @param  list<string>  $typed
     */
    private static function formOf(string $candidate, array $typed): bool
    {
        foreach ($typed as $word) {
            $core = self::core($word);
            if ($candidate === $word || (mb_strlen($word) >= 3 && (str_starts_with($candidate, $word) || str_starts_with($candidate, $core) || str_starts_with($word, self::core($candidate))))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a catalogue word is another word than one typed that only their ו and י tell apart ("עגול", "עגיל"): the
     * same letters once those are dropped (stem()), and neither the other's form.
     *
     * @param  list<string>  $typed
     */
    private static function vowelsApart(string $candidate, array $typed): bool
    {
        return array_any($typed, fn (string $word): bool => mb_strlen($word) >= 3 && self::stem(self::core($candidate)) === self::stem(self::core($word)) && ! self::formOf($candidate, [$word]));
    }

    /** A word of three letters or more anywhere in the words ("פרגולה" in "פרגולות"); a shorter one only as a word of its own, maybe after a prefix letter ("לב", "בלב", never "לבן"). */
    /**
     * Whether the catalogue has a word whole: as it is, or after a prefix letter ("ושבי" in "חטופים ושבי" has "שבי").
     *
     * @param  array<string, mixed>  $holders
     */
    public static function heldWhole(array $holders, string $word): bool
    {
        if (isset($holders[$word])) {
            return true;
        }
        foreach (['ו', 'ה', 'ב', 'ל', 'מ', 'ש', 'כ'] as $prefix) {
            if (isset($holders[$prefix.$word])) {
                return true;
            }
        }

        return false;
    }

    private static function holdsToken(string $hay, string $token, bool $whole = false): bool
    {
        // a word the catalogue has whole is held as a word, a prefix letter or two before it and an ending after it, never
        // inside a longer one: "שבי" is not in "שביל" (Sela, 26.10); any other is held as the start of one, as it is typed
        // an English word's plural is the word too ("tour" in "Israel Tours"), as a Hebrew word's endings are
        if ($whole) {
            return preg_match('/(?:^| )[והבלמשכ]{0,2}'.preg_quote($token, '/').'(?:ימ|ות|ה|י|ת|s|es)?(?: |$)/u', $hay) === 1;
        }

        return mb_strlen($token) >= 3 ? str_contains($hay, $token) : preg_match('/(?:^| )[והבלמשכ]?'.preg_quote($token, '/').'(?: |$)/u', $hay) === 1;
    }

    /**
     * 0 (best) to 5 when the record holds every word as typed; -1 when it does not.
     *
     * @param  array<string, mixed>  $whole  the words the catalogue has whole (the index's holders)
     */
    public static function exactTier(array $record, string $query, array $tokens, array $whole = []): int
    {
        foreach ($tokens as $token) {
            if (! self::holdsToken($record['hay'], $token, self::heldWhole($whole, $token))) {
                return -1;
            }
        }

        // The query as whole words before the query inside a longer word: "הרבי" finds "…של הרבי מלובביץ׳"
        // before "הרביעייה הפיננסית".
        return match (true) {
            $record['name'] === $query => 0,
            str_starts_with($record['name'].' ', $query.' ') => 1,
            str_contains(' '.$record['name'].' ', ' '.$query.' ') => 2,
            str_starts_with($record['name'], $query) => 3,
            str_contains(' '.$record['name'], ' '.$query) => 4,
            str_contains($record['name'], $query) => 5,
            default => self::allTokensStartWords($record['name'], $tokens) ? 6 : 7,
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
        // A filler word ("על", "של", "עם") is no hit either: "ספרים על חטופים" asks for the hostages, not for every
        // book whose text says "על". With no other word, the fillers are the query.
        $filler = array_filter($tokens, fn (string $t): bool => in_array($t, self::FILLER, true));
        $filler = count($filler) === count($tokens) ? [] : $filler;
        $counted = count(array_filter($tokens, fn (string $t, int $at): bool => ! in_array($t, self::DESCRIBES, true) && ! isset($generic[$at]) && ! isset($filler[$at]), ARRAY_FILTER_USE_BOTH)) ?: count($tokens);
        $needed = $counted <= 2 ? $counted : $counted - 1;
        // The words that must all be there for a record to hold the whole query: filler words
        // ("עם", "של") are not asked of it, nor "בצבע": "מקדחה בצבע שחור" is a black drill, and
        // a black hook "בצבע שחור" holds two of its words but not the drill.
        $required = array_keys(array_filter($tokens, fn (string $t, int $at): bool => ! in_array($t, self::FILLER, true) && ! in_array($t, self::DESCRIBES, true) && ! isset($generic[$at]), ARRAY_FILTER_USE_BOTH));
        $required = $required === [] ? array_keys($tokens) : $required;
        $matched = [];

        $bests = [];
        foreach ($tokens as $position => $token) {
            $best = [];

            foreach (self::closeWords($index, $token) as $word => $closeness) {
                foreach ($index['holders'][$word] ?? [] as $at) {
                    // A word in the name counts for more than one in the keywords; one of its tags, by how central.
                    $value = $closeness * self::fieldWeight($index['items'][$at], $word);

                    if (($best[$at] ?? 0) < $value) {
                        $best[$at] = $value;
                    }
                }
            }
            $bests[$position] = $best;
        }
        $rarity = self::rarity($bests, count($index['items']), fn (int $p): bool => isset($generic[$p]) || isset($filler[$p]));

        foreach ($bests as $position => $best) {
            foreach ($best as $at => $value) {
                // A generic word adds a little to the score of what holds it, never to what is found:
                // "כלים לגינה" puts the garden tools before paint that says "כלים". A rare word more than a common one.
                $score[$at] = ($score[$at] ?? 0) + $value * (isset($generic[$position]) || isset($filler[$position]) ? self::GENERIC_WEIGHT : $rarity[$position]);
                $hits[$at] = ($hits[$at] ?? 0) + (isset($generic[$position]) || isset($filler[$position]) ? 0 : 1);
                $matched[$at][$position] = true;
            }
        }

        foreach ($index['items'] as $at => $record) {
            $tier = self::exactTier($record, $query, $tokens, $index['holders'] ?? []);

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
     * How much each word of a search counts beside its others, by how rare it is in the catalogue: "פרגולה" (15 products)
     * says more than "עץ" (hundreds), so what holds the pergola comes before what holds only wood. ln((N+1)/(n+1)) + 1,
     * as a share of the search's average: one word, or words as rare, count as before. Generic and filler words aside.
     *
     * @param  array<int, array<int, float>>  $bests  by the word's position, the records that hold it
     * @return array<int, float>
     */
    public static function rarity(array $bests, int $count, callable $aside): array
    {
        $raw = [];
        foreach ($bests as $position => $best) {
            if (! $aside($position) && $best !== []) {
                $raw[$position] = log(($count + 1) / (count($best) + 1)) + 1;
            }
        }
        $mean = $raw === [] ? 1.0 : array_sum($raw) / count($raw);

        $out = [];
        foreach (array_keys($bests) as $position) {
            $out[$position] = isset($raw[$position]) ? $raw[$position] / $mean : 1.0;
        }

        return $out;
    }

    /** How much a word of the record counts: in its name, in a tag of it (by the tag's weight), or among its keywords. */
    private static function fieldWeight(array $record, string $word): float
    {
        if (str_contains($record['named'], ' '.$word.' ')) {
            return 1.0;
        }

        $tag = (float) ($record['tagged'][$word] ?? 0);

        return $tag > 0 ? self::KEYWORD_WEIGHT + self::TAG_WEIGHT * min(1.0, $tag) : self::KEYWORD_WEIGHT;
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

    /**
     * A search that is a product code, not words: one token of at least four digits ("53077",
     * "DDF-4850"). Its meaning is nothing; only the product of that code, or the words that hold it.
     */
    public static function looksLikeCode(?string $text): bool
    {
        $text = trim((string) $text);

        return $text !== '' && mb_strlen($text) <= 40 && preg_match('/\s/u', $text) !== 1 && preg_match_all('/\d/u', $text) >= 4;
    }

    /** @return list<string> the Hebrew words for a number written in digits (7: "שבע", "שבעה"), none past twelve */
    public static function numberWords(string $digits): array
    {
        return array_keys(self::NUMBER_WORDS, $digits, true);
    }

    /** The digits a Hebrew number word stands for ("שבע", "השבעה": 7), or null. */
    public static function numberOf(string $word): ?string
    {
        return self::NUMBER_WORDS[$word] ?? (mb_strlen($word) > 3 && str_starts_with($word, 'ה') ? self::NUMBER_WORDS[mb_substr($word, 1)] ?? null : null);
    }

    /** Hebrew number words as digits, one to twelve (both genders): "שבע באוקטובר" finds "ה-7 באוקטובר". */
    private const NUMBER_WORDS = [
        'אחד' => '1', 'אחת' => '1', 'שניים' => '2', 'שתיים' => '2', 'שני' => '2', 'שתי' => '2', 'שלוש' => '3', 'שלושה' => '3',
        'ארבע' => '4', 'ארבעה' => '4', 'חמש' => '5', 'חמישה' => '5', 'שש' => '6', 'שישה' => '6', 'שבע' => '7', 'שבעה' => '7',
        'שמונה' => '8', 'תשע' => '9', 'תשעה' => '9', 'עשר' => '10', 'עשרה' => '10', 'שתיםעשרה' => '12',
    ];

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
     * hebrew-search-engine.js has the same rule (single()); change both or neither.
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
     * or fewer than two words count. hebrew-search-engine.js has the same focus(); change both.
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
