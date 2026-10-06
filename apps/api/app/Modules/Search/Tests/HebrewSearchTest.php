<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\HebrewSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The search by spelling: the cases measured on the pilot store, and the promise that the
 * browser finds the same things in the same order as the server.
 */
final class HebrewSearchTest extends TestCase
{
    private const FIXTURE = __DIR__.'/fixtures/catalog.json';

    private const SCRIPT = __DIR__.'/../resources/search/rega-search.js';

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function typos(): iterable
    {
        foreach (self::fixture()['first'] as $query => $id) {
            yield $query => [(string) $query, $id];
        }
    }

    #[DataProvider('typos')]
    public function test_a_misspelled_query_finds_the_product_first(string $query, string $expected): void
    {
        $hits = HebrewSearch::search(HebrewSearch::build(self::fixture()['items']), $query);

        $this->assertNotEmpty($hits, "\"{$query}\" found nothing");
        $this->assertSame($expected, $hits[0]['id'], "\"{$query}\" found {$hits[0]['title']} first");
    }

    public function test_hebrew_is_normalized_before_anything_is_compared(): void
    {
        $this->assertSame('מברגה', HebrewSearch::normalize('מְבַרְגָה'), 'niqqud goes');
        $this->assertSame('עצ', HebrewSearch::normalize('עץ'), 'final letters take their regular form');
        $this->assertSame('42x92 ממ', HebrewSearch::normalize('42X92 מ"מ'), 'quotes go, case folds');
        $this->assertSame(HebrewSearch::stem(HebrewSearch::normalize('ברגים')), HebrewSearch::stem(HebrewSearch::normalize('בורג')), 'plural and defective spelling meet');
        $this->assertSame(HebrewSearch::sound('מקיטה'), HebrewSearch::sound('מקיטא'), 'letters that sound alike meet');
        $this->assertSame('מברגה', HebrewSearch::fromKeyboard('ncrdv'), 'an English keyboard is read as Hebrew');
        $this->assertSame('', HebrewSearch::fromKeyboard('מברגה'));
    }

    public function test_records_that_hold_the_words_as_typed_come_first(): void
    {
        $hits = HebrewSearch::search(HebrewSearch::build(self::fixture()['items']), 'ברגים');

        $this->assertTrue($hits[0]['exact']);
        $this->assertSame('p:4', $hits[0]['id']);
        $this->assertContains('c:500', array_column($hits, 'id'), 'a guide is found like a product');
    }

    public function test_nothing_is_found_for_words_the_shop_does_not_use(): void
    {
        $index = HebrewSearch::build(self::fixture()['items']);

        $this->assertSame([], HebrewSearch::search($index, 'קרש'));
        $this->assertSame([], HebrewSearch::search($index, '   '));
    }

    public function test_the_browser_finds_the_same_things_in_the_same_order(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $fixture = self::fixture();
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');'
            .'const f=require('.json_encode(realpath(self::FIXTURE)).');'
            .'const i=e.build(f.items);const out={};'
            .'for(const q of f.queries){out[q]=e.search(i,q).map(h=>h.id);}'
            .'process.stdout.write(JSON.stringify(out));';

        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);

        $index = HebrewSearch::build($fixture['items']);

        foreach ($fixture['queries'] as $query) {
            $server = array_column(HebrewSearch::search($index, $query), 'id');
            $this->assertSame($server, $browser[$query] ?? null, "\"{$query}\" differs between the server and the browser");
        }
    }
}
