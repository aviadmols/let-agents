<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * A stone that comes in one colour says its colour: a ruby is red, an emerald is green. A title names
 * the stone ("טבעת משובצת אבני רובי") and almost never its colour, so a search for "טבעת עם אבן
 * אדומה" found no ruby. Code only, from the stones already known (read in code or approved), and only
 * where nothing says a colour of its own and the shop's vocabulary holds that colour.
 *
 * Stones found in many colours (sapphire, topaz, tourmaline, diamond, pearl, spinel) say nothing.
 */
final class NaturalColours
{
    public const STONE = 'stone';

    public const COLOUR = 'stone_color';

    /** A stone's value key => its one colour's value key. */
    public const OF = [
        'ruby' => 'red',
        'garnet' => 'red',
        'carnelian' => 'red',
        'emerald' => 'green',
        'peridot' => 'green',
        'citrine' => 'yellow',
        'amethyst' => 'purple',
        'onyx' => 'black',
        'aquamarine' => 'light_blue',
    ];

    /**
     * The colours the product's stones imply; none when it already has a colour, when the colour does not
     * apply to its type, or when its stones imply two colours and the shop keeps one.
     *
     * @param  list<string>  $stones  the product's stone value keys
     * @param  list<string>  $colours  the colours already said of it
     * @return list<string>
     */
    public static function implied(?VocabularyDefinition $vocabulary, ?string $type, array $stones, array $colours): array
    {
        if ($vocabulary === null || $colours !== [] || ! $vocabulary->appliesTo(self::COLOUR, $type)) {
            return [];
        }

        $attribute = $vocabulary->attribute(self::COLOUR);
        if ($attribute === null || ($attribute['type'] ?? null) !== 'enum') {
            return [];
        }

        $known = array_map(fn (array $value): string => (string) $value['key'], (array) ($attribute['values'] ?? []));
        $out = [];
        foreach (array_unique($stones) as $stone) {
            $colour = self::OF[$stone] ?? null;
            if ($colour !== null && in_array($colour, $known, true)) {
                $out[$colour] = true;
            }
        }
        $out = array_keys($out);

        return count($out) > 1 && ! $vocabulary->isMultiple(self::COLOUR) ? [] : $out;
    }
}
