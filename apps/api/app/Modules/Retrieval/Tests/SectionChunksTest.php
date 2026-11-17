<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Retrieval\Support\Chunker;
use PHPUnit\Framework\TestCase;

/**
 * A page cut at its headings (8b): every piece begins with its path, the page's title and the headings it stands
 * under, a heading's number kept; a list item's number stays with its text; a table is never cut from its header.
 */
final class SectionChunksTest extends TestCase
{
    public function test_a_policy_page_is_cut_at_its_headings_and_each_piece_says_where_it_stands(): void
    {
        $text = "## מדיניות פרטיות\nתאריך עדכון: 24.01.2026\n### 1. איזה מידע אנחנו אוספים?\nשם, טלפון ומייל.\n"
            ."### 3. מסירת מידע לצד שלישי\nלא נמסור מידע לאיש.\n## משלוחים\n| שיטה | עלות |\n| --- | --- |\n| שליח | 20 ₪ |";

        $pieces = Chunker::sections('Privacy policy', $text, 1200);

        $this->assertSame([
            "Privacy policy > מדיניות פרטיות\nתאריך עדכון: 24.01.2026",
            "Privacy policy > מדיניות פרטיות > 1. איזה מידע אנחנו אוספים?\nשם, טלפון ומייל.",
            "Privacy policy > מדיניות פרטיות > 3. מסירת מידע לצד שלישי\nלא נמסור מידע לאיש.",
            "Privacy policy > משלוחים\n| שיטה | עלות |\n| --- | --- |\n| שליח | 20 ₪ |",
        ], $pieces, 'a heading of the same level leaves the one before it; the page title once');
        $this->assertSame(['מדיניות משלוחים > אספקה'."\n".'עד 5 ימים.'], Chunker::sections('מדיניות משלוחים', "## מדיניות משלוחים\n### אספקה\nעד 5 ימים.", 1200), 'a first heading that is the title is not said twice');
    }

    public function test_a_long_numbered_item_is_cut_between_sentences_never_after_its_number(): void
    {
        $item = '2. '.implode(' ', array_fill(0, 12, 'ככל שחלפו יותר מ-14 ימים ועד 30 ימים מיום קבלת המוצרים, המשתמש רשאי לקבל שובר.'));
        $pieces = Chunker::sections('החזרות', "## תנאים\n".$item, 400);

        $this->assertGreaterThan(1, count($pieces));
        $this->assertStringStartsWith("החזרות > תנאים\n2. ככל", $pieces[0], 'the number stays with its text');
        foreach ($pieces as $piece) {
            $this->assertStringStartsWith("החזרות > תנאים\n", $piece, 'every piece under its path');
            $this->assertLessThanOrEqual(400, mb_strlen($piece));
        }
        $this->assertSame(['כותרת'], Chunker::sections('כותרת', '  ', 400));
        $this->assertSame(["פוסט\nשורה אחת.\nשורה שתיים."], Chunker::sections('פוסט', "שורה אחת.\nשורה שתיים.", 400), 'no headings: as split() cuts it');
    }
}
