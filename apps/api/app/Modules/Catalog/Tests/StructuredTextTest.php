<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Text\HtmlText;
use PHPUnit\Framework\TestCase;

/**
 * A description's structure survives the way into the index: headings, list items, and a table whose
 * rows keep their values together with the header.
 */
final class StructuredTextTest extends TestCase
{
    public function test_headings_lists_and_tables_keep_their_structure(): void
    {
        $html = '<h2>מפרט טכני</h2><ul><li>הספק: <strong>620W</strong></li><li>סוג הפעלה: חשמלי</li><li></li></ul>'
            .'<p>לוחות בכמה עוביים:</p><table><tbody><tr><th>עובי</th><th>שימוש</th></tr><tr><td>9 מ&quot;מ</td><td>חיפוי קל</td></tr>'
            .'<tr><td>17 מ"מ</td><td>מדפים | ריהוט</td></tr></tbody></table><p>מתאים&nbsp;לגינה.</p><script>alert(1)</script>';

        $this->assertSame(implode("\n", [
            '## מפרט טכני',
            '',
            '- הספק: 620W',
            '- סוג הפעלה: חשמלי',
            '',
            'לוחות בכמה עוביים:',
            '',
            '| עובי | שימוש |',
            '| --- | --- |',
            '| 9 מ"מ | חיפוי קל |',
            '| 17 מ"מ | מדפים / ריהוט |',
            'מתאים לגינה.',
        ]), HtmlText::from($html));
    }

    public function test_a_table_without_header_cells_reads_its_first_row_as_the_header(): void
    {
        $this->assertSame("| עובי | שימוש |\n| --- | --- |\n| 9 | חיפוי |", HtmlText::from('<table><tr><td><b>עובי</b></td><td>שימוש</td></tr><tr><td>9</td><td>חיפוי</td></tr></table>'));
    }
}
