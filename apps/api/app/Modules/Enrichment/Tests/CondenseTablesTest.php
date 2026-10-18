<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\TextCondenser;
use PHPUnit\Framework\TestCase;

/**
 * Past the budget, a model still gets the details of the whole description before its prose: a table
 * row comes with its table's header row, so "9 מ״מ" is read as a thickness, not a loose number.
 */
final class CondenseTablesTest extends TestCase
{
    public function test_a_kept_table_row_brings_its_header_and_prose_goes_first(): void
    {
        $prose = str_repeat('לוחות איכותיים מעץ מלא בגימור טבעי ונעים למגע לשימוש בבית ובחוץ. ', 6);
        $text = implode("\n", [
            $prose,
            '## מפרט טכני',
            '| עובי | שימוש |',
            '| --- | --- |',
            '| 9 מ"מ | חיפוי קל |',
            '| 17 מ"מ | מדפים וריהוט |',
            $prose.' סוף.',
        ]);

        $out = (new TextCondenser)->condense(['לוח עץ', $text], 200, [], true);

        $this->assertTrue($out['truncated']);
        $this->assertStringContainsString("| עובי | שימוש |\n| --- | --- |\n| 9 מ\"מ | חיפוי קל |\n| 17 מ\"מ | מדפים וריהוט |", $out['text'], 'the rows with the header they need');
        $this->assertStringNotContainsString('סוף.', $out['text'], 'prose waits for the details');
    }
}
