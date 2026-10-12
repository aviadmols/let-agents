<?php

namespace ImageCaptionBenchmark;

use App\Modules\Search\Support\HebrewSearch;

/**
 * The normalized values a meta object may hold, each with the words (Hebrew and English) that name
 * it, and the unit readers for what a catalogue writes in its titles and descriptions.
 *
 * The same lists read the catalogue, constrain the image models, and read a shopper's query, so
 * the three speak one vocabulary.
 */
final class Vocabulary
{
    public const GROUPS = [
        'type' => [
            'ring' => ['טבעת', 'טבעות', 'ring', 'rings'],
            'earrings' => ['עגיל', 'עגילים', 'earring', 'earrings'],
            'necklace' => ['שרשרת', 'שרשראות', 'necklace', 'chain'],
            'pendant' => ['תליון', 'תליונים', 'pendant'],
            'bracelet' => ['צמיד', 'צמידים', 'bracelet'],
            'anklet' => ['צמיד רגל', 'anklet'],
            'brooch' => ['סיכה', 'brooch'],
            'watch' => ['שעון', 'watch'],
            'set' => ['סט', 'set'],
            'gift_card' => ['גיפט קארד', 'כרטיס מתנה', 'gift card'],
        ],
        'material' => [
            'white_gold' => ['זהב לבן', 'white gold'],
            'yellow_gold' => ['זהב צהוב', 'yellow gold'],
            'rose_gold' => ['זהב אדום', 'זהב ורוד', 'רוז גולד', 'rose gold'],
            'gold' => ['זהב', 'gold'],
            'silver' => ['כסף 925', 'כסף', 'sterling silver', 'silver'],
            'platinum' => ['פלטינה', 'platinum'],
            'stainless_steel' => ['נירוסטה', 'stainless steel'],
            'leather' => ['עור', 'leather'],
        ],
        'stone' => [
            'diamond' => ['יהלום', 'יהלומים', 'בריליאנט', 'diamond', 'diamonds'],
            'ruby' => ['רובי', 'ruby'],
            'sapphire' => ['ספיר', 'סאפיר', 'sapphire'],
            'emerald' => ['אמרלד', 'אזמרגד', 'emerald'],
            'pearl' => ['פנינה', 'פנינים', 'pearl', 'pearls'],
            'zircon' => ['זירקון', 'זירקונים', 'zircon', 'cubic zirconia'],
            'topaz' => ['טופז', 'בלו טופז', 'topaz'],
            'opal' => ['אופל', 'opal'],
            'onyx' => ['אוניקס', 'onyx'],
            'amethyst' => ['אמטיסט', 'amethyst'],
            'aquamarine' => ['אקוומרין', 'aquamarine'],
            'tanzanite' => ['טנזנייט', 'tanzanite'],
            'morganite' => ['מורגנייט', 'morganite'],
            'garnet' => ['גרנט', 'garnet'],
            'corundum' => ['קורונדום', 'corundum'],
        ],
        'color' => [
            'red' => ['אדום', 'אדומה', 'אדומים', 'אדומות', 'red'],
            'blue' => ['כחול', 'כחולה', 'כחולים', 'blue'],
            'light_blue' => ['תכלת', 'light blue'],
            'green' => ['ירוק', 'ירוקה', 'ירוקים', 'green'],
            'black' => ['שחור', 'שחורה', 'שחורים', 'black'],
            'white' => ['לבן', 'לבנה', 'לבנים', 'white'],
            'yellow' => ['צהוב', 'צהובה', 'yellow'],
            'pink' => ['ורוד', 'ורודה', 'pink'],
            'purple' => ['סגול', 'סגולה', 'purple'],
            'brown' => ['חום', 'חומה', 'brown'],
            'orange' => ['כתום', 'כתומה', 'orange'],
            'grey' => ['אפור', 'אפורה', 'grey', 'gray'],
            'silver' => ['כסוף', 'כסופה', 'silvery'],
            'gold' => ['זהוב', 'זהובה', 'golden'],
            'multicolor' => ['צבעוני', 'צבעונית', 'צבעוניים', 'multicolor'],
            'clear' => ['שקוף', 'שקופה', 'שקופים', 'transparent', 'clear'],
        ],
        'shape' => [
            'round' => ['עגול', 'עגולה', 'עגולים', 'round'],
            'oval' => ['אובלי', 'אובלית', 'oval'],
            'square' => ['מרובע', 'מרובעת', 'פרינסס', 'princess', 'square'],
            'rectangle' => ['מלבני', 'מלבנית', 'אמרלד קאט', 'emerald cut', 'rectangle'],
            'pear' => ['טיפה', 'pear', 'teardrop'],
            'heart' => ['לב', 'לבבות', 'heart'],
            'marquise' => ['מרקיזה', 'marquise'],
            'cushion' => ['קושן', 'cushion'],
            'baguette' => ['בגט', 'baguette'],
            'hoop' => ['חישוק', 'חישוקים', 'hoop'],
            'star' => ['כוכב', 'star'],
            'flower' => ['פרח', 'פרחים', 'flower'],
        ],
        'style' => [
            'solitaire' => ['סוליטר', 'solitaire'],
            'eternity' => ['איטרניטי', 'eternity'],
            'halo' => ['הילה', 'מוקפת', 'halo'],
            'three_stone' => ['שלוש אבנים', 'three stone'],
            'cluster' => ['אשכול', 'cluster'],
            'tennis' => ['טניס', 'tennis'],
            'vintage' => ['וינטג', 'וינטאג', 'vintage'],
            'minimal' => ['מינימליסטי', 'מינימליסטית', 'minimal'],
        ],
        'pattern' => [
            'twisted' => ['מתפתל', 'מתפתלת', 'מסובב', 'twisted'],
            'split_shank' => ['מפוצל', 'מפוצלת', 'split shank'],
            'striped' => ['פסים', 'striped'],
            'engraved' => ['חריטה', 'חרוט', 'engraved'],
            'hammered' => ['מרוקע', 'hammered'],
            'braided' => ['קלוע', 'קלועה', 'braided'],
            'textured' => ['מחוספס', 'textured'],
        ],
        'finish' => [
            'matte' => ['מט', 'מאט', 'matte'],
            'polished' => ['מבריק', 'מלוטש', 'polished'],
            'brushed' => ['מוברש', 'brushed'],
        ],
        'gender' => [
            'men' => ['גבר', 'גברים', 'לגבר', 'men', 'mens'],
            'women' => ['אישה', 'נשים', 'לאישה', 'women', 'womens'],
            'unisex' => ['יוניסקס', 'unisex'],
        ],
        'age_group' => [
            'kids' => ['ילדים', 'ילדה', 'ילד', 'תינוק', 'kids', 'baby'],
        ],
        'occasion' => [
            'engagement' => ['אירוסין', 'engagement'],
            'wedding' => ['חתונה', 'נישואין', 'wedding'],
            'gift' => ['מתנה', 'gift'],
        ],
    ];

    /** Readings in this order, each taking its words out of the text: "זהב לבן" is a material, not white. */
    public const ORDER = ['type', 'material', 'stone', 'color', 'shape', 'style', 'pattern', 'finish', 'gender', 'age_group', 'occasion'];

    /** Values that are one family: a model that sees white gold where the store says gold has not contradicted it. */
    public const FAMILIES = [
        'gold' => ['gold', 'yellow_gold', 'white_gold', 'rose_gold'],
        'gold_color' => ['gold', 'yellow'],
        'light' => ['white', 'silver', 'clear'],
        'pink' => ['pink', 'red'],
        'blue' => ['blue', 'light_blue'],
    ];

    /** A shopper's broad word covers the narrower ones: "זהב" finds white gold, "זהב לבן" does not find yellow. */
    public const NARROWER = [
        'gold' => ['yellow_gold', 'white_gold', 'rose_gold'],
        'blue' => ['light_blue'],
    ];

    /** Gold is sold in these karats; another number before "קראט" is a stone's weight. */
    public const KARATS = [8, 9, 10, 14, 18, 21, 22, 24];

    /**
     * Every group's values found in the text, longest words first, each found word taken out so a
     * later group does not read it again.
     *
     * @return array<string, list<string>> group => values
     */
    public static function read(string $text, array $groups = self::ORDER): array
    {
        $rest = ' '.HebrewSearch::normalize($text).' ';
        $found = [];

        foreach ($groups as $group) {
            $found[$group] = [];

            foreach (self::aliases($group) as [$alias, $value]) {
                $pattern = '/ [ובהמלשכ]{0,2}'.preg_quote($alias, '/').'(?= )/u';

                if (preg_match($pattern, $rest) === 1) {
                    $rest = (string) preg_replace($pattern, ' ', $rest);
                    $found[$group][] = $value;
                }
            }

            // "זהב צהוב" in the title and "זהב" in the description are one yellow gold, not also plain gold.
            foreach (self::NARROWER as $broad => $narrow) {
                if (in_array($broad, $found[$group], true) && array_intersect($narrow, $found[$group]) !== []) {
                    $found[$group] = array_diff($found[$group], [$broad]);
                }
            }

            $found[$group] = array_values(array_unique($found[$group]));
        }

        return $found;
    }

    /** @return list<string> */
    public static function values(string $group): array
    {
        return array_keys(self::GROUPS[$group]);
    }

    /** The words that name a value, for synonyms and for the semantic text. @return list<string> */
    public static function words(string $group, string $value): array
    {
        return self::GROUPS[$group][$value] ?? [];
    }

    /** The first Hebrew word for a value, for text a Hebrew shopper's words are compared with. */
    public static function hebrew(string $group, string $value): string
    {
        foreach (self::words($group, $value) as $word) {
            if (preg_match('/\p{Hebrew}/u', $word) === 1) {
                return $word;
            }
        }

        return str_replace('_', ' ', $value);
    }

    public static function sameFamily(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        foreach (self::FAMILIES as $members) {
            if (in_array($a, $members, true) && in_array($b, $members, true)) {
                return true;
            }
        }

        return false;
    }

    public static function covers(string $asked, string $held): bool
    {
        return $asked === $held || in_array($held, self::NARROWER[$asked] ?? [], true);
    }

    /** "משקל התכשיט 1.6 גרם": grams. @return array{value: float, raw: string}|null */
    public static function weightGrams(string $text): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(ק"ג|קג|קילו|kg|גרם|גרמים|גר\'|gr|g)(?![\p{L}])/iu', $text, $m) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $m[1]);
        $kilos = in_array(mb_strtolower($m[2]), ['ק"ג', 'קג', 'קילו', 'kg'], true);

        return ['value' => round($kilos ? $value * 1000 : $value, 3), 'raw' => trim($m[0])];
    }

    /** "זהב 14 קארט", "18K": the gold's karat, only a karat gold is sold in. */
    public static function karat(string $text): ?int
    {
        preg_match_all('/(?<![\d.,])(\d{1,2})\s*(?:קארט|קרט|קראט|kt|k)(?![\p{L}])/iu', $text, $m);

        foreach ($m[1] as $number) {
            if (in_array((int) $number, self::KARATS, true)) {
                return (int) $number;
            }
        }

        return null;
    }

    /** "יהלום מרכזי כ 0.5 קראט": stone weights in carats. @return list<float> */
    public static function stoneCarats(string $text): array
    {
        preg_match_all('/(\d+[.,]\d+)\s*(?:קראט|קרט|קארט|ct|carat|carats)(?![\p{L}])/iu', $text, $m);

        return array_values(array_map(fn (string $n): float => (float) str_replace(',', '.', $n), $m[1]));
    }

    /**
     * "אורך 45 ס"מ", "קוטר 12 מ"מ", "120x60 ס"מ": measurements in centimetres, with what was written.
     *
     * @return array<string, array{value: float, raw: string}>
     */
    public static function dimensions(string $text): array
    {
        $unit = '(ס"מ|סמ|סנטימטר|cm|מ"מ|ממ|מילימטר|mm|מטר|m|אינץ\'|inch|in)';
        $names = ['אורך' => 'length', 'length' => 'length', 'רוחב' => 'width', 'width' => 'width', 'גובה' => 'height', 'height' => 'height', 'קוטר' => 'diameter', 'diameter' => 'diameter'];
        $found = [];

        preg_match_all('/(אורך|רוחב|גובה|קוטר|length|width|height|diameter)\s*:?\s*(?:של\s*)?(\d+(?:[.,]\d+)?)\s*'.$unit.'(?![\p{L}])/iu', $text, $all, PREG_SET_ORDER);

        foreach ($all as $m) {
            $found[$names[mb_strtolower($m[1])]] ??= ['value' => self::centimetres((float) str_replace(',', '.', $m[2]), $m[3]), 'raw' => trim($m[0])];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×X\*]\s*(\d+(?:[.,]\d+)?)(?:\s*[x×X\*]\s*(\d+(?:[.,]\d+)?))?\s*'.$unit.'(?![\p{L}])/u', $text, $m) === 1) {
            $unitWord = $m[4];
            foreach (['length' => 1, 'width' => 2, 'height' => 3] as $name => $at) {
                if (($m[$at] ?? '') !== '') {
                    $found[$name] ??= ['value' => self::centimetres((float) str_replace(',', '.', $m[$at]), $unitWord), 'raw' => trim($m[0])];
                }
            }
        }

        return $found;
    }

    /** "מקט 11231", "מק"ט: 35375": the store's own code in its title. */
    public static function sku(string $text): ?string
    {
        return preg_match('/(?:מק"?ט|מקט|sku)\s*:?\s*([A-Za-z0-9][A-Za-z0-9\-]*)/iu', $text, $m) === 1 ? $m[1] : null;
    }

    /** @return list<array{0: string, 1: string}> [normalized word, value], longest first */
    private static function aliases(string $group): array
    {
        static $cache = [];

        if (! isset($cache[$group])) {
            $pairs = [];

            foreach (self::GROUPS[$group] as $value => $words) {
                foreach ($words as $word) {
                    $pairs[] = [HebrewSearch::normalize($word), $value];
                }
            }

            usort($pairs, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
            $cache[$group] = $pairs;
        }

        return $cache[$group];
    }

    private static function centimetres(float $value, string $unit): float
    {
        $unit = mb_strtolower($unit);

        return round(match (true) {
            in_array($unit, ['מ"מ', 'ממ', 'מילימטר', 'mm'], true) => $value / 10,
            in_array($unit, ['מטר', 'm'], true) => $value * 100,
            in_array($unit, ['אינץ\'', 'inch', 'in'], true) => $value * 2.54,
            default => $value,
        }, 2);
    }
}
