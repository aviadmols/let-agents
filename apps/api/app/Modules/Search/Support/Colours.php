<?php

namespace App\Modules\Search\Support;

/**
 * Colours as a search weighs them, in code: what colour each part of something is ("זהב צהוב", "אבן
 * שקופה", "כיס שחור", "ג'ינס כחול"), read from words, and how well a product's own words (its title,
 * attributes and what its picture was read as) agree. A part's colour near that part's word in the
 * product counts most; the colour anywhere counts less; a product that says the same part in another
 * colour, or another metal, counts against. So a yellow-gold ring with a white stone comes before a
 * white-gold one, and black pockets on blue jeans are not black jeans.
 */
final class Colours
{
    /** Metals exclude each other: a ring is yellow, white or rose gold. */
    public const METAL = 'metal';

    public const COLOUR = 'colour';

    /** Words apart within which a colour belongs to the part named next to it. */
    private const NEAR = 3;

    /**
     * family => [group, stems]. A stem matches a word that starts with it ("שחור" holds "שחורה", "שחורים"). A gem is
     * its colour too ("אמרלד" is green, "רובי" red): a store names its stones, not their colours.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const FAMILIES = [
        'gold_yellow' => [self::METAL, ['זהוב', 'yellow gold', 'gold tone']],
        'gold_white' => [self::METAL, ['כסוף', 'כסף', 'פלטינה', 'white gold', 'silver', 'platinum']],
        'gold_rose' => [self::METAL, ['ורדרד', 'רוז', 'rose gold']],
        'black' => [self::COLOUR, ['שחור', 'black']],
        'white' => [self::COLOUR, ['לבן', 'לבנ', 'שמנת', 'white', 'ivory', 'cream']],
        'clear' => [self::COLOUR, ['שקוף', 'שקופ', 'clear', 'transparent']],
        'red' => [self::COLOUR, ['אדום', 'אדומ', 'בורדו', 'red', 'burgundy', 'רובי', 'אודם', 'גרנט', 'גארנט', 'ruby', 'garnet']],
        'pink' => [self::COLOUR, ['ורוד', 'ורוד', 'pink', 'מורגניט', 'morganite']],
        'blue' => [self::COLOUR, ['כחול', 'כחל', 'נייבי', 'blue', 'navy', 'denim', 'ספיר', 'טנזנייט', 'טנזניט', 'לאפיס', 'sapphire', 'tanzanite', 'lapis']],
        'light_blue' => [self::COLOUR, ['תכלת', 'טורקיז', 'אקוומרין', 'light blue', 'turquoise', 'aqua', 'aquamarine']],
        'green' => [self::COLOUR, ['ירוק', 'ירוק', 'זית', 'green', 'olive', 'אמרלד', 'אזמרגד', 'ברקת', 'פרידוט', 'ירקן', 'emerald', 'peridot', 'jade']],
        'yellow' => [self::COLOUR, ['צהוב', 'צהב', 'חרדל', 'yellow', 'mustard', 'ציטרין', 'citrine']],
        'orange' => [self::COLOUR, ['כתום', 'כתומ', 'orange']],
        'purple' => [self::COLOUR, ['סגול', 'סגל', 'לילך', 'purple', 'lilac', 'אמטיסט', 'amethyst']],
        'brown' => [self::COLOUR, ['חום', 'שוקולד', 'קאמל', 'brown', 'camel']],
        'beige' => [self::COLOUR, ['בז', 'beige', 'nude']],
        'grey' => [self::COLOUR, ['אפור', 'אפור', 'grey', 'gray']],
    ];

    /** Colours a shopper does not tell apart on a stone: a "white" diamond is a clear one. */
    private const ALIKE = [['white', 'clear']];

    /** Families that are light, or dark, when no word with them says which: as a shopper sees a colour's tone. */
    private const LIGHT = ['white', 'clear', 'beige', 'yellow', 'light_blue', 'pink', 'gold_white'];

    private const DARK = ['black', 'brown', 'blue', 'purple', 'red', 'green'];

    /** Words that make a colour light, or dark ("צהוב בהיר", "ירוק כהה", "pale yellow"). */
    private const LIGHTER = '/בהיר|פסטל|חלבי|שמנת|light|pale|pastel|cream/iu';

    private const DARKER = '/כהה|עמוק|dark|deep/iu';

    /**
     * The colour a picture's words name first, the one it shows most ("צהוב בהיר" before the "לבן שבור" of its
     * buttons), with its tone: light, dark, or mid. Null when they name no colour.
     *
     * @param  list<string>  $words  the picture's words, the most telling first
     * @return array{family: string, tone: string}|null
     */
    public static function dominant(array $words): ?array
    {
        foreach ($words as $phrase) {
            $mentions = self::mentions((string) $phrase);
            if ($mentions === []) {
                continue;
            }
            $family = $mentions[0]['family'];
            $tone = match (true) {
                preg_match(self::LIGHTER, (string) $phrase) === 1 => 'light',
                preg_match(self::DARKER, (string) $phrase) === 1 => 'dark',
                in_array($family, self::LIGHT, true) => 'light',
                in_array($family, self::DARK, true) => 'dark',
                default => 'mid',
            };

            return ['family' => $family, 'tone' => $tone];
        }

        return null;
    }

    /**
     * How near two pictures' colours look: the same colour 1 (its tone apart .8), another colour of the same tone
     * .5 (a pale yellow shirt beside a white one, not a dark green one), else 0; 0 when either names none.
     *
     * @param  array{family: string, tone: string}|null  $a
     * @param  array{family: string, tone: string}|null  $b
     */
    public static function near(?array $a, ?array $b): float
    {
        return match (true) {
            $a === null || $b === null => 0.0,
            $a['family'] === $b['family'] => $a['tone'] === $b['tone'] ? 1.0 : 0.8,
            $a['tone'] === $b['tone'] && $a['tone'] !== 'mid' => 0.5,
            default => 0.0,
        };
    }

    /** Words for one part: a stone is a stone whatever the store calls it. */
    private const PART_ALIASES = [
        ['אבן', 'אבנ', 'יהלום', 'זירקון', 'קריסטל', 'סוליטר', 'stone', 'diamond', 'zircon', 'crystal'],
        ['כיס', 'pocket'],
        ['שרוול', 'sleeve'],
        ['צווארון', 'collar'],
        ['רצועה', 'רצוע', 'strap'],
    ];

    /** The metal named by "זהב" with the word after it. */
    private const GOLD = ['צהוב' => 'gold_yellow', 'לבן' => 'gold_white', 'אדום' => 'gold_rose', 'ורוד' => 'gold_rose', 'רוז' => 'gold_rose', 'yellow' => 'gold_yellow', 'white' => 'gold_white', 'rose' => 'gold_rose'];

    /**
     * The colours in a text, each with the group it excludes within and where it is.
     *
     * @return list<array{family: string, group: string, at: int}>
     */
    public static function mentions(string $text): array
    {
        $words = self::tokens($text);
        $found = [];

        for ($i = 0, $n = count($words); $i < $n; $i++) {
            $word = $words[$i];

            // "זהב צהוב" / "yellow gold": the metal, and the colour word after it is the metal's, not its own.
            if (in_array($word, ['זהב', 'gold'], true)) {
                $next = $words[$i + 1] ?? '';
                $before = $words[$i - 1] ?? '';
                $family = self::goldOf($next) ?? self::goldOf($before);

                if ($family !== null) {
                    $found[] = ['family' => $family, 'group' => self::METAL, 'at' => $i];
                    $i += self::goldOf($next) !== null ? 1 : 0;

                    continue;
                }
            }

            foreach (self::FAMILIES as $family => [$group, $stems]) {
                foreach ($stems as $stem) {
                    if (! str_contains($stem, ' ') && self::starts($word, $stem)) {
                        $found[] = ['family' => $family, 'group' => $group, 'at' => $i];

                        continue 3;
                    }
                }
            }
        }

        // Two-word names ("white gold", "light blue") in English.
        $joined = ' '.implode(' ', $words).' ';
        foreach (self::FAMILIES as $family => [$group, $stems]) {
            foreach ($stems as $stem) {
                if (str_contains($stem, ' ') && str_contains($joined, ' '.$stem.' ')) {
                    $found[] = ['family' => $family, 'group' => $group, 'at' => count(explode(' ', trim(strstr($joined, ' '.$stem.' ', true))))];
                }
            }
        }

        return $found;
    }

    /**
     * Each colour the reader saw, with the part it is the colour of (the phrase's first word that is
     * not itself a colour) and how much it weighs: a metal, and the first details, more.
     *
     * @param  list<string>  $details  the reader's phrases, the most telling first
     * @return list<array{family: string, group: string, part: string|null, weight: float}>
     */
    public static function parts(array $details): array
    {
        $parts = [];
        $seen = [];

        foreach (array_values($details) as $at => $phrase) {
            $words = self::tokens((string) $phrase);
            $mentions = self::mentions((string) $phrase);
            $colourAt = array_column($mentions, 'at');
            $part = null;

            foreach ($words as $i => $word) {
                if (! in_array($i, $colourAt, true) && mb_strlen($word) >= 2 && ! self::isColourWord($word) && ! in_array($word, ['זהב', 'gold', 'בצבע', 'צבע', 'עם', 'של', 'in', 'with'], true)) {
                    $part = $word;
                    break;
                }
            }
            // A metal is its own part: "זהב צהוב" is the colour of the metal, wherever it shows.
            $part = $mentions !== [] && array_filter($mentions, fn (array $m): bool => $m['group'] === self::METAL) !== [] && count($mentions) === 1 ? null : $part;

            foreach ($mentions as $mention) {
                // "לבנה שקופה" on one stone is one colour, not two.
                $family = $mention['family'];
                foreach (self::ALIKE as $set) {
                    $family = in_array($family, $set, true) ? $set[0] : $family;
                }
                $key = $family.'|'.($part ?? '');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $parts[] = [
                    'family' => $mention['family'],
                    'group' => $mention['group'],
                    'part' => $part,
                    'weight' => ($mention['group'] === self::METAL ? 1.5 : 1.0) * ($at < 2 ? 1.2 : 1.0),
                ];
            }
        }

        return $parts;
    }

    /**
     * How well a product's own words agree with the colours seen, from -1 (they say otherwise) to 1
     * (each part in its colour). 0 when nothing was seen or the product says nothing of colour.
     *
     * @param  list<array{family: string, group: string, part: string|null, weight: float}>  $parts
     */
    public static function score(array $parts, string $productText): float
    {
        if ($parts === []) {
            return 0.0;
        }

        $words = self::tokens($productText);
        $mentions = self::mentions($productText);

        if ($mentions === []) {
            return 0.0;
        }

        $total = 0.0;
        $score = 0.0;

        // Where each part the photo shows is named in the product, to tell whose colour a colour is.
        $partsAt = [];
        foreach ($parts as $part) {
            if ($part['part'] !== null) {
                $partsAt[$part['part']] = array_keys(array_filter($words, fn (string $w): bool => self::samePart($w, (string) $part['part'])));
            }
        }

        foreach ($parts as $part) {
            $total += $part['weight'];
            $partAt = $part['part'] === null ? [] : $partsAt[$part['part']];
            $nearTo = fn (array $at, array $m): bool => $at !== [] && min(array_map(fn (int $p): int => abs($p - $m['at']), $at)) <= self::NEAR;
            $near = fn (array $m): bool => $nearTo($partAt, $m);
            // A colour next to another part of the photo is that part's ("ג'ינס שחור" is not "כיסים שחורים").
            $elsewhere = function (array $m) use ($part, $partsAt, $nearTo, $partAt): bool {
                // A metal is the whole piece's, wherever it is named.
                if ($part['part'] === null) {
                    return false;
                }

                foreach ($partsAt as $name => $at) {
                    if ($name !== $part['part'] && $nearTo($at, $m) && ! ($partAt !== [] && $nearTo($partAt, $m))) {
                        return true;
                    }
                }

                return false;
            };
            $same = array_values(array_filter($mentions, fn (array $m): bool => self::alike($m['family'], $part['family']) && ! $elsewhere($m)));
            $others = array_values(array_filter($mentions, fn (array $m): bool => ! self::alike($m['family'], $part['family']) && $m['group'] === $part['group']));

            $value = match (true) {
                // The part in its colour ("כיס שחור" in "ג'ינס כחול עם כיסים שחורים").
                $same !== [] && ($partAt === [] || array_filter($same, $near) !== []) => 1.0,
                // The colour, but the part named in another one: "כיס שחור" against "כיס כחול".
                $partAt !== [] && array_filter($others, $near) !== [] => -0.8,
                // The colour somewhere.
                $same !== [] => 0.5,
                // Another metal only: a white-gold ring for a yellow-gold photo.
                $part['group'] === self::METAL && $others !== [] => -1.0,
                default => 0.0,
            };

            $score += $part['weight'] * $value;
        }

        return $total > 0 ? max(-1.0, min(1.0, $score / $total)) : 0.0;
    }

    private static function alike(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        foreach (self::ALIKE as $set) {
            if (in_array($a, $set, true) && in_array($b, $set, true)) {
                return true;
            }
        }

        return false;
    }

    private static function samePart(string $word, string $part): bool
    {
        if (self::starts($word, $part) || self::starts($part, $word)) {
            return true;
        }

        foreach (self::PART_ALIASES as $aliases) {
            $a = array_filter($aliases, fn (string $x): bool => self::starts($word, $x));
            $b = array_filter($aliases, fn (string $x): bool => self::starts($part, $x));

            if ($a !== [] && $b !== []) {
                return true;
            }
        }

        return false;
    }

    private static function isColourWord(string $word): bool
    {
        if (self::goldOf($word) !== null) {
            return true;
        }

        foreach (self::FAMILIES as [, $stems]) {
            foreach ($stems as $stem) {
                if (! str_contains($stem, ' ') && self::starts($word, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function goldOf(string $word): ?string
    {
        foreach (self::GOLD as $stem => $family) {
            if (self::starts($word, $stem)) {
                return $family;
            }
        }

        return null;
    }

    private static function starts(string $word, string $stem): bool
    {
        if ($word === $stem) {
            return true;
        }

        // "הלבן", "ושחור", "בזהב": the word without the one letter Hebrew puts before it.
        if (mb_strlen($word) >= 4 && preg_match('/^[הובלמשכ]/u', $word) === 1 && self::starts(mb_substr($word, 1), $stem)) {
            return true;
        }

        // A short stem ("בז") only as the whole word; a longer one with its endings ("שחורה", "לבנים").
        return mb_strlen($stem) >= 3 && str_starts_with($word, $stem) && mb_strlen($word) - mb_strlen($stem) <= 3;
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        $text = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $text)));
        $text = str_replace(["'", '״', '׳'], '', $text);

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], fn (string $w): bool => $w !== ''));
    }
}
