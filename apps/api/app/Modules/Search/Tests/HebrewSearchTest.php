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

    private const SCRIPT = __DIR__.'/../resources/search/hebrew-search-engine.js';

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

    public function test_a_word_the_catalogue_has_whole_is_never_the_start_of_a_longer_one(): void
    {
        // Sela: "שבי" (captivity) found "שביל הזהב" first, as the start of "שביל".
        $index = HebrewSearch::build([
            ['id' => 'p:1', 'title' => 'שביל הזהב', 'keywords' => 'טיולים'],
            ['id' => 'p:2', 'title' => 'מפגש עם עצמי בגיהינום', 'keywords' => 'חטופים ושבי'],
            ['id' => 'p:3', 'title' => 'מקדחה Makita', 'keywords' => ''],
        ]);

        $this->assertSame(['p:2'], array_column(HebrewSearch::search($index, 'שבי'), 'id'), 'held whole, with its prefix letter ("ושבי")');
        $this->assertSame(['p:1'], array_column(HebrewSearch::search($index, 'שביל'), 'id'));
        $this->assertSame(['p:3'], array_column(HebrewSearch::search($index, 'מקד'), 'id'), 'a part no product has whole still starts a word, as typed');
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
        $this->assertSame(HebrewSearch::stem('מלחמה'), HebrewSearch::stem('מלחמת'), 'a noun and its construct form meet');
        $this->assertSame(HebrewSearch::stem('קופסה'), HebrewSearch::stem('קופסא'), 'and an ending written with א');
    }

    public function test_a_date_is_one_thing_however_it_is_written_on_the_server_and_in_the_browser(): void
    {
        // Sela Meir, 28.10: "השביעי לאוקטובר" found only the bundle whose picture read "שביעי באוקטובר", and it stood
        // alone; the books filed under "גבורה ותקומה אחרי 7.10" and "להבין את ה-7 באוקטובר" held "7", not "שביעי".
        $cases = [
            'השביעי לאוקטובר' => '7 אוקטובר', '7.10' => '7 אוקטובר', '7/10' => '7 אוקטובר', 'ה-7 באוקטובר' => '7 אוקטובר', 'שבעה באוקטובר' => '7 אוקטובר',
            'גבורה ותקומה אחרי 7.10' => 'גבורה ותקומה אחרי 7 אוקטובר', 'להבין את ה-7 באוקטובר' => 'להבינ את 7 אוקטובר', '7.10.23' => '7 אוקטובר 23', '1/5/2024' => '1 מאי 2024',
            'ספרי השבעה באוקטובר' => 'ספרי 7 אוקטובר', 'הראשון בינואר' => '1 ינואר',
            // a measure, a product of sizes, a version: no date
            '4.5 מ"מ' => '4 5 ממ', '10.5 cm' => '10 5 cm', '19x120 מ"מ' => '19x120 ממ', '3.14.1' => '3 14 1', '32.10' => '32 10',
        ];

        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, HebrewSearch::normalize($text), $text);
        }

        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const c='.json_encode(array_keys($cases), JSON_UNESCAPED_UNICODE).';'
            .'process.stdout.write(JSON.stringify(c.map(t=>e.normalize(t))));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();

        $this->assertSame(array_values($cases), json_decode($process->getOutput(), true), 'the browser reads dates as the server does');
    }

    public function test_the_query_as_a_whole_word_comes_before_it_inside_a_longer_word(): void
    {
        // A book store's "הרבי": the book about the Rebbe, not "הרביעייה" that only starts with the letters.
        $index = HebrewSearch::build([
            ['id' => 'p:1', 't' => 'product', 'title' => 'הרביעייה הפיננסית'],
            ['id' => 'p:2', 't' => 'product', 'title' => 'עושה שלום – משנתו המדינית של הרבי מלובביץ׳'],
            ['id' => 'p:3', 't' => 'product', 'title' => 'מלחמת העולם'],
            ['id' => 'p:4', 't' => 'product', 'title' => 'שורשי המלחמה'],
        ]);

        $this->assertSame('p:2', HebrewSearch::search($index, 'הרבי')[0]['id']);
        $this->assertContains('p:3', array_column(HebrewSearch::search($index, 'מלחמה'), 'id'), '"מלחמה" finds "מלחמת העולם"');
    }

    public function test_a_word_the_catalogue_holds_is_that_word_not_one_its_vowel_letters_make_alike(): void
    {
        // Rocks, 25.10: "עגילי זהב" found rings "בחיתוך עגול": with ו and י dropped, "עגיל" and "עגול" are one stem.
        $items = [
            ['id' => 'p:1', 't' => 'product', 'title' => 'עגילי וינטג צמודים', 'keywords' => 'עגילים זהב'],
            ['id' => 'p:2', 't' => 'product', 'title' => 'טבעת בחיתוך עגול', 'keywords' => 'טבעות זהב'],
            ['id' => 'p:3', 't' => 'product', 'title' => 'חולצה לבנה'],
        ];
        $index = HebrewSearch::build($items);

        $this->assertSame(['p:1'], array_column(HebrewSearch::search($index, 'עגילי זהב'), 'id'), 'earrings, not a ring cut round');
        $this->assertSame(['p:2'], array_column(HebrewSearch::search($index, 'עגול'), 'id'), 'and round is round');
        $this->assertSame(['p:3'], array_column(HebrewSearch::search($index, 'חולצה לבינה'), 'id'), 'a slip nothing plainly holds is still forgiven');

        $node = (new ExecutableFinder)->find('node');
        if ($node !== null) {
            $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const i=e.build('.json_encode($items, JSON_UNESCAPED_UNICODE).');'
                .'process.stdout.write(JSON.stringify(["עגילי זהב","עגול","חולצה לבינה"].map(q=>e.search(i,q).map(h=>h.id))));';
            $process = new Process([$node, '-e', $harness]);
            $process->mustRun();
            $this->assertSame([['p:1'], ['p:2'], ['p:3']], json_decode($process->getOutput(), true), 'the browser reads them the same');
        }
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

    public function test_the_box_tells_a_question_from_words_to_look_up(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $cases = [
            'כמה ברגים צריך למטר דק?' => true,
            'האם איפאה מתאים ליד בריכה' => true,
            'איך מתקינים דק' => true,
            'how do I oil a deck' => true,
            'ברגים לדק' => false,
            'מקיטה' => false,
            'איך' => false,
            '??' => false,
        ];
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const out={};'
            .'for(const q of '.json_encode(array_keys($cases), JSON_UNESCAPED_UNICODE).'){out[q]=e.isQuestion(q);}'
            .'process.stdout.write(JSON.stringify(out));';

        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();

        $this->assertSame($cases, json_decode($process->getOutput(), true));
    }

    public function test_a_saved_answer_shows_only_for_the_same_question(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $screws = 'כמה ברגים צריך למטר דק?';
        $wall = 'האם זה מתאים לקיר גבס?';
        $cases = [
            ['מה כי', $wall, false],
            ['מה כי', $screws, false],
            ['ברגים', $screws, false],
            ['קיר', $wall, false],
            ['מה מתאים לגבס', $wall, false],
            ['כמה ברגים צר', $screws, false],
            ['כמה ברגים צריך למטר', $screws, true],
            ['כמה ברגים צריך למטר דק', $screws, true],
            ['האם מתאים לקיר גבס', $wall, true],
        ];
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');'
            .'process.stdout.write(JSON.stringify('.json_encode($cases, JSON_UNESCAPED_UNICODE).'.map(c=>e.closeAnswer(c[0],c[1]))));';

        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();

        $this->assertSame(array_column($cases, 2), json_decode($process->getOutput(), true), 'a loose match never shows an answer to another question');
    }

    public function test_a_generic_word_is_not_asked_of_a_product(): void
    {
        $engine = HebrewSearch::build([
            ['id' => 'p:1', 'title' => 'מקלטר 4 שיניים', 'keywords' => 'גינון גינה'],
            ['id' => 'p:2', 'title' => 'ארגז כלים', 'keywords' => ''],
            ['id' => 'p:3', 'title' => 'ספריי צבע לכלים ולריהוט גינה', 'keywords' => ''],
        ]);

        // "כלים לגינה" is about the garden: the rake is found though it never says "כלים", and a box
        // that says "כלים" alone is not; what says both is not put before what says the garden.
        $ids = array_column(HebrewSearch::search($engine, HebrewSearch::normalize('כלים לגינה')), 'id');
        $this->assertContains('p:1', $ids);
        $this->assertNotContains('p:2', $ids);
        // With no other word, a generic word is the query.
        $this->assertContains('p:2', array_column(HebrewSearch::search($engine, HebrewSearch::normalize('כלים')), 'id'));
    }

    public function test_the_words_a_vector_reads_end_in_their_final_letters(): void
    {
        // Mycolivia: "פטריה שטובה ללישון" reached the meaning search as "ללישונ", no word at all (.45 fell to .18).
        $this->assertSame('פטריה שטובה ללישון', HebrewSearch::withFinals(HebrewSearch::normalize('פטריה שטובה ללישון')));
        $this->assertSame('כמון נענע קרשים חוף ארץ', HebrewSearch::withFinals('כמונ נענע קרשימ חופ ארצ'));
        $this->assertSame('sleep well 7', HebrewSearch::withFinals('sleep well 7'), 'English as it is');
    }
}
