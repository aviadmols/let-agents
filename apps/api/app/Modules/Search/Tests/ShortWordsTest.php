<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\HebrewSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A word of one or two Hebrew letters in the catalogue ("מל" for מ"ל, "סמ" for ס"מ) is found only as typed: "שמלה", read as
 * ש and "מלה", found every cream of 70 מ"ל by its stem and by its sound (Take Me Home, 17.11). The drawer as the server.
 */
final class ShortWordsTest extends TestCase
{
    private const ITEMS = [
        ['id' => 'p:oil', 'title' => 'שמן לתינוק 200 מ״ל', 'keywords' => ''],
        ['id' => 'p:cream', 'title' => 'קרם פנים לתינוק 70 מ"ל', 'keywords' => 'טיפוח'],
        ['id' => 'p:towel', 'title' => 'מגבת 70 ס"מ', 'keywords' => ''],
        ['id' => 'p:set', 'title' => 'סט בייבי תות', 'keywords' => 'שמלה וכובע'],
    ];

    private const EXPECTED = ['שמלה' => ['p:set'], 'שמלות' => ['p:set'], 'שמן' => ['p:oil'], 'בסמל' => [], '70 מ"ל' => ['p:cream']];

    public function test_a_two_letter_word_of_the_catalogue_is_no_form_of_a_longer_one(): void
    {
        $index = HebrewSearch::build(self::ITEMS);
        foreach (self::EXPECTED as $query => $ids) {
            $this->assertSame($ids, array_column(HebrewSearch::search($index, (string) $query), 'id'), "\"{$query}\"");
        }
    }

    public function test_the_drawer_reads_them_as_the_server_does(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }
        $harness = 'const e=require('.json_encode(realpath(__DIR__.'/../resources/search/hebrew-search-engine.js')).');const i=e.build('.json_encode(self::ITEMS, JSON_UNESCAPED_UNICODE).');const out={};'
            .'for(const q of '.json_encode(array_map('strval', array_keys(self::EXPECTED)), JSON_UNESCAPED_UNICODE).'){out[q]=e.search(i,q).map(h=>h.id);}process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);
        foreach (self::EXPECTED as $query => $ids) {
            $this->assertSame($ids, $browser[(string) $query], "\"{$query}\" in the drawer");
        }
    }
}
