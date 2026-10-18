<?php

namespace App\Modules\Catalog\Tests;

use App\Modules\Catalog\Enums\ColorShade;
use App\Modules\Catalog\Support\ColorWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorWordsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>, 3: list<string>}> name, shades, families, patterns */
    public static function names(): array
    {
        return [
            'a plain colour' => ['שחור', ['black'], ['black'], []],
            'feminine' => ['כחולה', ['blue'], ['blue'], []],
            'plural, final letter turned regular' => ['לבנים', ['white'], ['white'], []],
            'dark is its own shade, not blue as well' => ['כחול כהה', ['navy'], ['blue'], []],
            'english' => ['Navy Blue', ['navy'], ['blue'], []],
            'separators' => ['Off-White', ['off_white'], ['white', 'beige'], []],
            'hebrew geresh' => ['בז׳', ['beige'], ['beige'], []],
            'two colours with a conjunction' => ['שחור ולבן', ['black', 'white'], ['black', 'white'], []],
            'two colours with a slash' => ['Black/White', ['black', 'white'], ['black', 'white'], []],
            'a pattern in the name' => ['פסים כחול-לבן', ['blue', 'white'], ['blue', 'white'], ['stripes']],
            'a pattern alone' => ['Leopard', [], [], ['animal']],
            'a shade on two families' => ['טורקיז', ['turquoise'], ['blue', 'green'], []],
            'jewellery: rose gold, not gold and red' => ['זהב אדום', ['rose_gold'], ['gold', 'pink'], []],
            'a prefix' => ['בשחור', ['black'], ['black'], []],
            'a word inside another is not it' => ['כחול', ['blue'], ['blue'], []],
            'a name nobody knows' => ['Ocean Mist', [], [], []],
        ];
    }

    #[DataProvider('names')]
    public function test_a_store_colour_name_reads_as_shades_families_and_patterns(string $name, array $shades, array $families, array $patterns): void
    {
        $read = ColorWords::read($name);

        $this->assertSame($shades, array_map(fn ($s) => $s->value, $read['shades']), $name);
        $this->assertSame($families, array_map(fn ($f) => $f->value, $read['families']), $name);
        $this->assertSame($patterns, array_map(fn ($p) => $p->value, $read['patterns']), $name);
    }

    public function test_sand_is_not_read_inside_blue(): void
    {
        // "חול" (sand) sits inside "כחול": a whole word only.
        $this->assertSame([ColorShade::Blue], ColorWords::read('כחול')['shades']);
    }

    public function test_a_swatch_tells_the_nearest_shade(): void
    {
        $this->assertSame(ColorShade::Navy, ColorWords::nearest('#1c2b4b'));
        $this->assertSame(ColorShade::Black, ColorWords::nearest('#000'));
        $this->assertNull(ColorWords::nearest('blue'));
        $this->assertSame('#AABBCC', ColorWords::hex('abc'));
    }
}
