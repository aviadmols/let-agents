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

    public function test_a_numbered_list_keeps_its_numbers_nested_under_their_parent(): void
    {
        // every item writes "1." itself (Miss Nori's terms, 17.11); the list says which it is
        $html = '<ol><li><b>כללי</b><ol style="list-style:auto;"><li>1. ברוכים הבאים לאתר.</li><li>1. ההוראות יחולו.</li></ol></li>'
            .'<li><b>רכישה</b><ol><li>3.1. המשתמשת כשירה.</li><li>תשלום בכרטיס.</li></ol></li></ol><ol start="5"><li>חמישי</li></ol><ul><li>נקודה</li></ul>';

        $this->assertSame("1. כללי\n1.1. ברוכים הבאים לאתר.\n1.2. ההוראות יחולו.\n\n2. רכישה\n3.1. המשתמשת כשירה.\n2.2. תשלום בכרטיס.\n\n- חמישי\n\n- נקודה", HtmlText::from($html), 'a plain numbered list stays as it was');
        $this->assertSame("5. a\n6. b", HtmlText::from('<ol start="5"><li>1. a</li><li>1. b</li></ol>'), 'its own numbers contradicted: its place, from its start');
    }
}
