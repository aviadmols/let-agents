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
}
