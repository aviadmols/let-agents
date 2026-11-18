<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Text\ShortcodeText;
use App\Modules\Catalog\Support\FeedRecord;
use PHPUnit\Framework\TestCase;

/**
 * A page builder's shortcodes left in a WordPress page's text: its raw HTML block decoded and read as rows, its tags
 * gone, and a text without them unchanged (Gueta's shipping table, 18.11).
 */
final class ShortcodeTextTest extends TestCase
{
    private static function raw(string $html): string
    {
        return base64_encode(rawurlencode($html));
    }

    public function test_a_raw_html_block_is_read_as_its_table(): void
    {
        $table = '<table><tr><td><strong>שיטת משלוח</strong></td><td><strong>עלות</strong></td></tr><tr><td>שליח עד הבית</td><td>45 ש"ח</td></tr><tr><td>הובלה</td><td>1000 ש"ח</td></tr></table>';
        $text = '[vc_row][vc_column width="1/6"][/vc_column][vc_column width="2/3"][vc_empty_space woodmart_hide_large="0"][vc_raw_html]'
            .self::raw($table).'[/vc_raw_html][/vc_column][/vc_row]משלוחים לכל הארץ.';

        $this->assertSame("| שיטת משלוח | עלות |\n| --- | --- |\n| שליח עד הבית | 45 ש\"ח |\n| הובלה | 1000 ש\"ח |\nמשלוחים לכל הארץ.", ShortcodeText::readable($text));
    }

    public function test_a_block_cut_by_the_end_of_the_text_keeps_what_decodes(): void
    {
        $encoded = self::raw('<p>שליח עד הבית 45 ש"ח</p><p>הובלה 350 ש"ח ועוד הרבה טקסט שנחתך באמצע</p>');
        $out = (string) ShortcodeText::readable('[vc_raw_html]'.substr($encoded, 0, (int) (strlen($encoded) * 0.6)));

        $this->assertStringContainsString('שליח עד הבית 45 ש"ח', $out);
        $this->assertStringNotContainsString('vc_raw_html', $out);
    }

    public function test_builder_tags_go_and_a_title_stays(): void
    {
        $this->assertSame("שאלות נפוצות\nאפשר להזמין טלפונית.", ShortcodeText::readable('[wd_asp id=1] [su_box title="שאלות נפוצות"]אפשר להזמין טלפונית.[/su_box]'));
    }

    public function test_text_without_builder_tags_is_unchanged(): void
    {
        foreach (["מידות [ראו טבלה] ועוד\n\n## כותרת  ", '[1] הערה', 'ללא סוגריים כלל', 'not base64 [vc] tag'] as $text) {
            $this->assertSame($text, ShortcodeText::readable($text));
        }
        $this->assertNull(ShortcodeText::readable(null));
    }

    public function test_the_feed_stores_the_readable_text_and_its_hash_moves_only_when_it_changed(): void
    {
        $plain = ['external_id' => '1', 'title' => 'משלוחים', 'text' => 'משלוח חינם מעל 300'];
        $this->assertSame(FeedRecord::hash(['external_id' => '1', 'title' => 'משלוחים', 'text' => 'משלוח חינם מעל 300', 'type' => 'page', 'terms' => []]), FeedRecord::content($plain + ['type' => 'page'])['hash']);

        $built = FeedRecord::content(['external_id' => '2', 'type' => 'page', 'text' => '[vc_row][vc_column]משלוח חינם מעל 300[/vc_column][/vc_row]']);
        $this->assertSame('משלוח חינם מעל 300', $built['body']);
    }
}
