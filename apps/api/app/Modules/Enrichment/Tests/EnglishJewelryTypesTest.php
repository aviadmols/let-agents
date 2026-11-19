<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\PatternMatch;
use PHPUnit\Framework\TestCase;

/**
 * The jewelry vocabulary reads a product's type in English too: its patterns were Hebrew only, so an English store read no
 * type, the vocabulary was held for it ("no_type_read", 14 of 100) and the store had no filters of metal or stone (Jean
 * Rachel's demo, 300 products, 19.11). In English the type is the word that closes the name, or comes before a dash, a
 * comma or "with"; a word before another noun is not ("Ring Necklace" is a necklace). Hebrew names read as before.
 */
final class EnglishJewelryTypesTest extends TestCase
{
    /** @return list<string> the types whose patterns find the text */
    private static function types(string $text): array
    {
        $vocabulary = json_decode((string) file_get_contents(__DIR__.'/../resources/vocabularies/jewelry.json'), true);

        return array_values(array_map(fn (array $t): string => $t['key'], array_filter($vocabulary['product_types'], fn (array $t): bool => PatternMatch::first($text, $t['patterns']) !== null)));
    }

    public function test_an_english_name_reads_its_one_type(): void
    {
        $this->assertSame(['ring'], self::types('Statement Sterling Silver CZ Wrap Ring'));
        $this->assertSame(['ring'], self::types('Sterling Silver Hammered Band'));
        $this->assertSame(['earrings'], self::types('Sterling Silver Freshwater Pearl Earrings'));
        $this->assertSame(['necklace'], self::types('14K Gold Over Sterling Silver Emerald CZ Necklace'));
        $this->assertSame(['necklace'], self::types('Gold Ring Necklace'), 'a word before another noun is no type of its own');
        $this->assertSame(['set'], self::types('Sterling Silver Layered Necklace Set with Onyx'));
        $this->assertSame(['ring'], self::types('Emerald Ring - Size 7'));
        $this->assertSame(['gift_card'], self::types('Jean Rachel eGift Card'));
        $this->assertSame(['ring'], self::types('Ring'), "the store's own type, a word alone");
    }

    public function test_a_hebrew_name_reads_as_before(): void
    {
        $this->assertSame(['ring'], self::types('טבעת וינטג\' משובצת רובי, זהב 14K'));
        $this->assertSame(['pendant'], self::types('תליון מגן דוד רקוע בעבודת יד, ללא שרשרת - מק"ט 14975, זהב 21K'));
    }
}
