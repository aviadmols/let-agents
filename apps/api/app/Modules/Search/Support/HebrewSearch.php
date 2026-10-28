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
    private const COLORS = [['שחור', 'שחורה', 'שחורימ', 'שחורות'], ['לבנ', 'לבנה', 'לבנימ', 'לבנות'], ['אדומ', 'אדומה', 'אדומימ', 'אדומות'], ['כחול', 'כחולה', 'כחולימ', 'כחולות'], ['ירוק', 'ירוקה', 'ירוקימ', 'ירוקות'], ['צהוב', 'צהובה', 'צהובימ', 'צהובות'], ['אפור', 'אפורה', 'אפורימ', 'אפורות'], ['כתומ', 'כתומה', 'כתומימ', 'כתומות'], ['סגול', 'סגולה', 'סגולימ', 'סגולות'], ['ורוד', 'ורודה', 'ורודימ', 'ורודות'], ['כסופ', 'כסופה', 'כסופימ', 'כסופות'], ['זהוב', 'זהובה', 'זהובימ', 'זהובות'], ['שקופ', 'שקופה', 'שקופימ', 'שקופות']];

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

                // "חולצה מידה M", "טבעת במידה 52": a value after the word for size is that size, however short.
                if ($name !== '' && preg_match('/\s((?:[ובה]?מיד(?:ה|ת)|size)\s?:?\s?'.preg_quote($name, '/').')(?=\s)/u', $text, $m) === 1) {
                    $found[] = ['code' => $at * 1000 + $i, 'said' => $m[1], 'label' => (string) $value['l'], 'title' => (string) ($facet['l'] ?? '')];

                    continue;
                }

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

        // nothing left but words that join the rest ("עם", "של"): as nothing left. "טבעות עם יהלום" with "טבעות" and
        // "יהלום" read as filters searched "עם" and found the 27 rings whose words say it, of 973 (Rocks, 26.10)
        $left = array_filter(explode(' ', self::normalize($stripped)), fn (string $w): bool => $w !== '' && ! self::isFiller($w));
        if ($left === [] || $stripped === $query) {
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
        if ($whole) {
            return preg_match('/(?:^| )[והבלמשכ]{0,2}'.preg_quote($token, '/').'(?:ימ|ות|ה|י|ת)?(?: |$)/u', $hay) === 1;
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
