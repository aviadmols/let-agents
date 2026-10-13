<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\PhotoPlacer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The loose stem that places what a photo shows among the shop's words. */
final class PhotoPlacerTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function sameThing(): array
    {
        return [
            ['ברז', 'ברזים'], ['ברז מטבח', 'ברזי מטבח'], ['מברגה', 'מברגות'], ['פרגולה', 'פרגולות'], ['ידית', 'ידיות'],
            ['עץ', 'עצים'], ['כלי עבודה', 'כלים'], ['מקדחה', 'מקדחות'], ['מברשת', 'מברשות'], ['סוללה', 'סוללות'], ['שולחן', 'שולחנות'],
            ['כיסא', 'כיסאות'], ['חולצה', 'חולצות'], ['דלת', 'דלתות'], ['צבע', 'צבעים'], ['ארגז כלים', 'ארגזי כלים'],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function differentThings(): array
    {
        return [['ברז', 'ברזל'], ['ידית', 'ברז'], ['מברגה', 'מברג'], ['עץ', 'עצה'], ['דק', 'דקל']];
    }

    #[DataProvider('sameThing')]
    public function test_one_word_finds_its_plural_and_its_construct_form(string $seen, string $named): void
    {
        $this->assertTrue(PhotoPlacer::agrees($seen, $named), "{$seen} should agree with {$named}");
    }

    #[DataProvider('differentThings')]
    public function test_a_word_inside_another_word_is_not_the_same_word(string $seen, string $named): void
    {
        $this->assertFalse(PhotoPlacer::agrees($seen, $named), "{$seen} should not agree with {$named}");
    }

    public function test_the_head_noun_leads_and_repeats_are_dropped(): void
    {
        $this->assertSame([['ברז'], ['מטבח']], PhotoPlacer::words('ברז מטבח, ברז'));
        $this->assertSame(['מברגת', 'מברג', 'מברגה'], PhotoPlacer::forms('מברגות'), 'a plural in ות may be of a masculine or a feminine noun');
        $this->assertSame([], PhotoPlacer::words(' '));
    }

    public function test_what_it_is_made_of_never_chooses_the_category(): void
    {
        $index = ['records' => [
            'k:1' => ['id' => 'k:1', 't' => 'category', 'title' => 'טבעות', 'n' => 400],
            'k:2' => ['id' => 'k:2', 't' => 'category', 'title' => 'טבעות זהב צהוב', 'n' => 60],
        ], 'facets' => [
            ['k' => 'type', 'l' => 'סוג מוצר', 'v' => [['k' => 'ring', 'l' => 'טבעת']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'yellow_gold', 'l' => 'זהב צהוב'], ['k' => 'white_gold', 'l' => 'זהב לבן']]],
        ]];

        // "זהב" says what it is made of: a signet ring with gold letters is a ring, not only among yellow-gold rings.
        $this->assertSame('טבעות', (new PhotoPlacer($index))->place('טבעת חותם עם לוח שחור ואותיות זהב')['main']['title']);
        // Without the shop's vocabulary, the words choose as before.
        $this->assertSame('טבעות זהב צהוב', (new PhotoPlacer(['records' => $index['records']]))->place('טבעת חותם עם לוח שחור ואותיות זהב')['main']['title']);
        // A plural in יות stands for its short singular too: אותיות, אות.
        $this->assertContains('את', PhotoPlacer::forms('אותיות'));
    }
}
