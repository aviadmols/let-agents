<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\HebrewSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A grade, a size or a code matches as a whole token (8f, 17.11): "14K" is 14K and never 4K, "18 מ"מ" never 8 מ"מ, and a
 * number typed is a whole number, "14" going on as "14K" but never as "140". The drawer finds the same as the server.
 */
final class WholeNumberTest extends TestCase
{
    private const ITEMS = [
        ['id' => 'p:1', 'title' => 'טבעת זהב 14K סוליטר', 'keywords' => ''],
        ['id' => 'p:2', 'title' => 'טבעת זהב 4K', 'keywords' => ''],
        ['id' => 'p:3', 'title' => 'שרשרת זהב 9K', 'keywords' => ''],
        ['id' => 'p:4', 'title' => 'לוח פוליקרבונט 18 מ"מ', 'keywords' => ''],
        ['id' => 'p:5', 'title' => 'לוח פוליקרבונט 8 מ"מ', 'keywords' => ''],
        ['id' => 'p:6', 'title' => 'מסגרת 140 ס"מ', 'keywords' => ''],
    ];

    private const EXPECTED = ['14K' => ['p:1'], '9K' => ['p:3'], '18 מ"מ' => ['p:4'], '8 מ"מ' => ['p:5'], '14' => ['p:1'], '140' => ['p:6']];

    public function test_grades_sizes_and_numbers_match_whole(): void
    {
        $index = HebrewSearch::build(self::ITEMS);
        foreach (self::EXPECTED as $query => $ids) {
            $this->assertSame($ids, array_column(HebrewSearch::search($index, (string) $query), 'id'), "\"{$query}\"");
        }
    }

    public function test_the_drawer_matches_them_as_the_server_does(): void
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
