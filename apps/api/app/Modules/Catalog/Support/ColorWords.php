<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\ColorFamily;
use App\Modules\Catalog\Enums\ColorShade;
use App\Modules\Catalog\Enums\FabricPattern;

/**
 * What a store's name for a colour option says, read in code: "כחול כהה" is navy, "Black/White"
 * is black and white, "פסים כחול-לבן" is blue and white and striped. Meant for the value of a
 * colour option, not for running text: "חלק" is plain here, a part in a title.
 *
 * The longest name is read first and then taken out, so "כחול כהה" is navy and not blue as well.
 * A Hebrew word marked "~" is read in all its forms (כחול, כחולה, כחולים, כחולות), and any word
 * may carry the prefixes ו, ב, ה ("שחור ולבן", "בשחור"). A name nothing here knows reads as
 * nothing; a swatch, when the store has one, still tells its shade (nearest).
 */
final class ColorWords
{
    /** @var array<string, list<string>> shade => the names it goes by */
    private const SHADES = [
        'black' => ['~שחור', 'black', 'jet black', 'onyx'],
        'charcoal' => ['פחם', 'אנטרציט', 'גרפיט', 'charcoal', 'anthracite', 'graphite'],
        'dark_grey' => ['~אפור ~כהה', 'dark grey', 'dark gray'],
        'grey' => ['~אפור', "מלאנז'", 'grey', 'gray', 'melange', 'heather grey', 'heather gray'],
        'light_grey' => ['~אפור ~בהיר', 'light grey', 'light gray'],
        'white' => ['~לבן', 'white', 'optic white', 'snow'],
        'off_white' => ['אוף וויט', 'אוף ווייט', 'אקרו', 'שנהב', 'איבורי', 'off white', 'offwhite', 'ecru', 'ivory'],
        'cream' => ['שמנת', 'קרם', 'וניל', 'cream', 'vanilla'],
        'beige' => ["בז'", 'beige', 'oatmeal', 'oat'],
        'sand' => ['חול', 'sand', 'sandy'],
        'camel' => ['קאמל', 'קוניאק', 'טאן', 'camel', 'cognac', 'tan'],
        'taupe' => ['טאופ', 'מוקה', 'taupe', 'mocha'],
        'nude' => ['ניוד', 'nude'],
        'brown' => ['~חום', 'קפה', 'brown', 'coffee'],
        'chocolate' => ['~חום ~כהה', 'שוקולד', 'אספרסו', 'dark brown', 'chocolate', 'espresso'],
        'khaki' => ['חאקי', 'khaki'],
        'olive' => ['~ירוק זית', 'זית', 'olive'],
        'green' => ['~ירוק', 'green', 'kelly green'],
        'dark_green' => ['~ירוק ~כהה', '~ירוק בקבוק', 'אמרלד', 'dark green', 'bottle green', 'forest green', 'forest', 'emerald'],
        'light_green' => ['~ירוק ~בהיר', 'ליים', 'פיסטוק', 'light green', 'lime', 'pistachio'],
        'mint' => ['מנטה', 'מרווה', 'mint', 'sage'],
        'turquoise' => ['טורקיז', 'אקווה', 'turquoise', 'aqua'],
        'petrol' => ['פטרול', 'petrol', 'teal'],
        'light_blue' => ['תכלת', '~כחול ~בהיר', 'סקיי', 'light blue', 'sky blue', 'baby blue', 'ice blue', 'sky'],
        'blue' => ['~כחול', 'קובלט', 'blue', 'cobalt'],
        'royal_blue' => ['~כחול רויאל', 'רויאל', 'royal blue', 'royal', 'electric blue'],
        'navy' => ['~כחול ~כהה', 'נייבי', 'נייווי', 'navy', 'navy blue', 'dark blue', 'midnight'],
        'denim' => ["ג'ינס", 'דנים', 'אינדיגו', 'denim', 'indigo'],
        'purple' => ['~סגול', 'שזיף', 'חציל', 'purple', 'violet', 'plum', 'eggplant'],
        'lilac' => ['~סגול ~בהיר', 'לילך', 'לבנדר', 'lilac', 'lavender'],
        'burgundy' => ['~אדום ~כהה', 'בורדו', 'יין', 'דובדבן', 'burgundy', 'bordeaux', 'wine', 'maroon', 'cherry', 'dark red'],
        'red' => ['~אדום', 'red', 'scarlet'],
        'coral' => ['קורל', 'אלמוג', 'סלמון', 'coral', 'salmon'],
        'orange' => ['~כתום', 'orange'],
        'rust' => ['חלודה', 'טרקוטה', "ג'ינג'ר", 'נחושת', 'rust', 'terracotta', 'brick', 'ginger', 'copper'],
        'mustard' => ['חרדל', 'אוקר', 'mustard', 'ochre'],
        'yellow' => ['~צהוב', 'לימון', 'yellow', 'lemon'],
        'pink' => ['~ורוד', '~ורוד עתיק', 'pink', 'rose', 'dusty pink'],
        'light_pink' => ['~ורוד ~בהיר', 'פודרה', 'בייבי פינק', 'light pink', 'baby pink', 'blush', 'powder'],
        'fuchsia' => ['פוקסיה', 'פוקסייה', "מג'נטה", 'fuchsia', 'magenta', 'hot pink'],
        'peach' => ['אפרסק', 'משמש', 'peach', 'apricot'],
        'gold' => ['~זהוב', 'זהב', 'זהב צהוב', 'gold', 'golden', 'yellow gold'],
        'silver' => ['~כסוף', 'כסף', 'זהב לבן', 'פלטינה', 'silver', 'white gold', 'platinum'],
        'rose_gold' => ['רוז גולד', 'זהב אדום', 'זהב ורוד', 'rose gold', 'rosegold', 'pink gold'],
        'multi' => ['~צבעוני', 'מולטי', 'multi', 'multicolor', 'multicolour', 'multi color', 'rainbow'],
    ];

    /** @var array<string, list<string>> pattern => the names it goes by */
    private const PATTERNS = [
        'solid' => ['חלק', 'solid', 'plain'],
        'stripes' => ['פסים', 'פס', 'מפוספס', 'מפוספסת', 'stripe', 'stripes', 'striped', 'pinstripe'],
        'checks' => ['משבצות', 'משובץ', 'משובצת', 'פפיטה', 'check', 'checks', 'checked', 'plaid', 'tartan', 'gingham', 'houndstooth'],
        'floral' => ['פרחוני', 'פרחונית', 'פרחים', 'floral', 'flowers', 'flower'],
        'dots' => ['נקודות', 'מנוקד', 'מנוקדת', 'פולקה', 'dots', 'dot', 'dotted', 'polka', 'polka dot'],
        'animal' => ['מנומר', 'מנומרת', 'נמר', 'זברה', 'נחש', 'animal', 'leopard', 'zebra', 'snake', 'cheetah'],
        'camo' => ['הסוואה', "קמופלאז'", 'camo', 'camouflage'],
        'tie_dye' => ['טאי דאי', 'בטיק', 'tie dye', 'tiedye', 'batik'],
        'print' => ['הדפס', 'מודפס', 'מודפסת', 'print', 'printed', 'graphic', 'paisley', 'פייזלי'],
    ];

    /** The prefixes a Hebrew word takes in a name: "ולבן", "בשחור", "השחור". */
    private const PREFIXES = '(?:וה|וב|ו|ב|ה)?';

    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** @var list<array{0: string, 1: string, 2: string}>|null phrase, kind (shade|pattern), key; longest first */
    private static ?array $phrases = null;

    /** @var array<string, array{shades: list<ColorShade>, families: list<ColorFamily>, patterns: list<FabricPattern>}> */
    private static array $read = [];

    /**
     * The shades, their families and the patterns a name says, in the order it says them.
     *
     * @return array{shades: list<ColorShade>, families: list<ColorFamily>, patterns: list<FabricPattern>}
     */
    public static function read(string $name): array
    {
        // A store names its colours a few dozen ways, over and over: each is read once.
        if (isset(self::$read[$name])) {
            return self::$read[$name];
        }

        if (count(self::$read) > 5000) {
            self::$read = [];
        }

        return self::$read[$name] = self::readName($name);
    }

    /** @return array{shades: list<ColorShade>, families: list<ColorFamily>, patterns: list<FabricPattern>} */
    private static function readName(string $name): array
    {
        $text = self::normalize($name);
        $found = [];

        if ($text !== '') {
            foreach (self::phrases() as [$phrase, $kind, $key]) {
                $pattern = '/(?<![\p{L}\p{N}])'.self::PREFIXES.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u';

                if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$match, $offset]) {
                    $found[] = [$offset, $kind, $key];
                    // Taken out, the same length in bytes, so the offsets of the rest still hold.
                    $text = substr_replace($text, str_repeat(' ', strlen($match)), $offset, strlen($match));
                }
            }
        }

        usort($found, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $shades = [];
        $patterns = [];
        foreach ($found as [, $kind, $key]) {
            if ($kind === 'shade') {
                $shades[$key] = ColorShade::from($key);
            } else {
                $patterns[$key] = FabricPattern::from($key);
            }
        }

        $families = [];
        foreach ($shades as $shade) {
            foreach ($shade->families() as $family) {
                $families[$family->value] = $family;
            }
        }

        return ['shades' => array_values($shades), 'families' => array_values($families), 'patterns' => array_values($patterns)];
    }

    /** Whether the name says a colour or a pattern at all. */
    public static function knows(string $name): bool
    {
        $read = self::read($name);

        return $read['shades'] !== [] || $read['patterns'] !== [];
    }

    /**
     * The shade closest to a swatch, by how far apart the eye sees them (CIE Lab): "Ocean Mist" at
     * #5F8A99 is petrol. Null for what is not a colour code.
     */
    public static function nearest(string $hex): ?ColorShade
    {
        $lab = self::lab($hex);

        if ($lab === null) {
            return null;
        }

        $best = null;
        $distance = INF;

        foreach (ColorShade::cases() as $shade) {
            $other = $shade->hex() === null ? null : self::lab($shade->hex());

            if ($other === null) {
                continue;
            }

            $d = ($lab[0] - $other[0]) ** 2 + ($lab[1] - $other[1]) ** 2 + ($lab[2] - $other[2]) ** 2;

            if ($d < $distance) {
                [$best, $distance] = [$shade, $d];
            }
        }

        return $best;
    }

    /** "#1b2a4a", "1B2A4A" and "#12a" are colour codes; anything else is not. */
    public static function hex(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match) !== 1) {
            return null;
        }

        $digits = strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) : $match[1];

        return '#'.strtoupper((string) $digits);
    }

    /** Lower case, one kind of apostrophe, separators as spaces: "Off-White" is "off white", "בז׳" is "בז'". */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['׳' => "'", '’' => "'", '`' => "'", '״' => '"']);
        $text = (string) preg_replace('/[\/\\\\|,;+&·()\[\]{}_\-–—.:"]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function phrases(): array
    {
        if (self::$phrases !== null) {
            return self::$phrases;
        }

        $phrases = [];
        foreach (['shade' => self::SHADES, 'pattern' => self::PATTERNS] as $kind => $names) {
            foreach ($names as $key => $list) {
                foreach ($list as $name) {
                    foreach (self::forms($name) as $phrase) {
                        $phrases[$phrase] ??= [$phrase, $kind, $key];
                    }
                }
            }
        }

        $phrases = array_values($phrases);
        // The longest first, then by spelling, so the order never depends on the list's.
        usort($phrases, fn (array $a, array $b): int => [mb_strlen($b[0]), $a[0]] <=> [mb_strlen($a[0]), $b[0]]);

        return self::$phrases = $phrases;
    }

    /**
     * Every spelling of a name: each "~" word in its four forms ("~כחול ~כהה" is "כחול כהה",
     * "כחולה כהה", "כחולים כהים", ...). A mismatched pair ("כחולה כהים") is read too; no store writes it.
     *
     * @return list<string>
     */
    private static function forms(string $name): array
    {
        $forms = [''];

        foreach (explode(' ', self::normalize(str_replace('~', ' ~', $name))) as $word) {
            if ($word === '') {
                continue;
            }

            $spellings = str_starts_with($word, '~') ? self::inflect(substr($word, 1)) : [$word];
            $next = [];
            foreach ($forms as $form) {
                foreach ($spellings as $spelling) {
                    $next[] = trim($form.' '.$spelling);
                }
            }
            $forms = $next;
        }

        return array_values(array_unique($forms));
    }

    /**
     * A Hebrew adjective in the masculine and feminine, single and plural: "לבן" is "לבנה",
     * "לבנים", "לבנות" (a final letter turns regular before an ending); "כהה" is "כהים", "כהות".
     *
     * @return list<string>
     */
    private static function inflect(string $word): array
    {
        if (str_ends_with($word, 'ה')) {
            $stem = mb_substr($word, 0, -1);

            return [$word, $stem.'ים', $stem.'ות'];
        }

        $last = mb_substr($word, -1);
        $stem = mb_substr($word, 0, -1).(self::FINALS[$last] ?? $last);

        return [$word, $stem.'ה', $stem.'ים', $stem.'ות'];
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private static function lab(string $hex): ?array
    {
        $hex = self::hex($hex);

        if ($hex === null) {
            return null;
        }

        $linear = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        [$r, $g, $b] = $linear;
        $xyz = [
            ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047,
            ($r * 0.2126 + $g * 0.7152 + $b * 0.0722),
            ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883,
        ];
        [$x, $y, $z] = array_map(fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116, $xyz);

        return [116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z)];
    }
}
