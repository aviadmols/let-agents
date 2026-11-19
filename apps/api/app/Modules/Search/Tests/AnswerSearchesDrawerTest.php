<?php

namespace App\Modules\Search\Tests;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The drawer's side of the searches an answer leads into (AnswerSearchesTest is the server's), run in Node over a small store
 * (fixtures/drawer-harness.js): each a button under the answer, its count what it opens; pressed, the drawer's own search
 * turn for its words with its filters chosen as read from them, so each is taken off as a word is. Rocks, 19.11.
 */
final class AnswerSearchesDrawerTest extends TestCase
{
    /** Rings and a bracelet: kind 0-1, metal 1000-1001, stone 2000. */
    private const STORE = <<<'JS'
        var S = T.S;
        S.facets = [
          { k: 'type', l: 'Type', v: [{ k: 'ring', l: 'Ring' }, { k: 'bracelet', l: 'Bracelet' }] },
          { k: 'metal', l: 'Metal', v: [{ k: 'white', l: 'White gold' }, { k: 'yellow', l: 'Yellow gold' }] },
          { k: 'stone', l: 'Stone', v: [{ k: 'diamond', l: 'Diamond' }] }
        ];
        var P = function (id, title, f, pr, o) { return Object.assign({ id: 'p:' + id, t: 'product', title: title, s: 1, kw: '', f: f, pr: pr }, o || {}); };
        [
          P(1, 'Classic diamond ring', [0, 1000, 2000], 2500),
          P(2, 'Fine diamond ring', [0, 1000, 2000], 2800),
          P(3, 'Grand diamond ring', [0, 1000, 2000], 5000),
          P(4, 'Sold out diamond ring', [0, 1000, 2000], 1000, { s: 0 }),
          P(5, 'Yellow diamond ring', [0, 1001, 2000], 900),
          P(6, 'Ring with a stone', [0, 1000, 2000], 900, { no: [2000] }),
          P(7, 'Diamond bracelet', [1, 1000, 2000], 900)
        ].forEach(function (r) { S.records[r.id] = r; });
        var chips = [{ k: 'type', v: 'ring', l: 'Ring' }, { k: 'stone', v: 'diamond', l: 'Diamond' }, { k: 'metal', v: 'white', l: 'White gold' }];
        var answer = { id: 'a1', kind: 'answer', q: 'a diamond ring in white gold under 3000?', answer: 'Yes.', picks: [{ id: '2' }], searches: [
          { label: 'Ring · Diamond · White gold', query: 'Ring Diamond White gold', chips: chips, price: { min: null, max: 3000 }, count: 2 },
          { label: 'Ring · Diamond', query: 'Ring Diamond', chips: chips.slice(0, 2), price: null, count: 4 }
        ] };
        S.turns.push(answer);
        JS;

    private static function drawer(string $expression): mixed
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }
        $process = new Process([$node, __DIR__.'/fixtures/drawer-harness.js', dirname(__DIR__).'/resources/search', '(function () {'.self::STORE.' return ('.$expression.'); })()']);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }

    public function test_each_search_is_a_button_under_the_answer_with_what_it_opens_counted(): void
    {
        $buttons = self::drawer('T.answerSearches(answer).map(function (x) { return [x.i, x.label, x.n]; })');

        $this->assertSame([[0, 'Ring · Diamond · White gold · Up to ₪3,000', 2], [1, 'Ring · Diamond', 4]], $buttons, 'the price in the store\'s currency; in stock, never one a check refused');
        $html = self::drawer('T.answerSearchesHtml(answer)');
        $this->assertStringContainsString('data-answer-search="a1" data-i="0"', $html);
        $this->assertStringContainsString('Ring · Diamond · White gold · Up to ₪3,000 <span class="ls-num">(2)</span>', $html);
    }

    public function test_pressing_a_button_opens_the_search_turn_with_its_filters_chosen_as_read_from_its_words(): void
    {
        $opened = self::drawer('(function () { var x = T.answerSearchOpen(answer, 0); return { kind: x.kind, q: x.q, ids: x.ids, auto: x.c.auto, at: x.c.at, f: x.c.f, stock: x.c.autoStock, price: x.price, shown: T.filtered(x).map(function (r) { return r.id; }) }; })()');

        $this->assertSame('results', $opened['kind'], 'the drawer\'s own search turn, filters, sort and all');
        $this->assertSame('Ring Diamond White gold under 3000', $opened['q'], 'its words, the price as the search reads it');
        $this->assertSame(['0' => true, '1000' => true, '2000' => true], $opened['auto'], 'every value chosen as if read from the words');
        $this->assertSame(['0' => [0], '1000' => [2, 3], '2000' => [1]], $opened['at'], 'each with its words: taken off, the search goes on without them');
        $this->assertSame([], $opened['f']);
        $this->assertTrue($opened['stock'], 'in stock by default, as every search');
        $this->assertEquals(['min' => null, 'max' => 3000, 'rest' => 'Ring Diamond White gold'], $opened['price'], 'the price a pill of its own, the words left searched once it is taken off');
        $this->assertSame(['p:2', 'p:1', 'p:4'], $opened['ids'], 'the answer\'s pick first; never the dear one, the yellow one, the refused one or the bracelet');
        $this->assertSame(['p:2', 'p:1'], $opened['shown'], 'what the button counted');
    }

    public function test_a_search_this_index_cannot_open_or_holds_fewer_than_two_of_has_no_button(): void
    {
        // a value the index here does not have (rebuilt since), and a search of one product
        $this->assertSame([], self::drawer("(function () { answer.searches = [{ label: 'Ring · Ruby', chips: [{ k: 'type', v: 'ring', l: 'Ring' }, { k: 'stone', v: 'ruby', l: 'Ruby' }], price: null, count: 3 }, { label: 'Bracelet · Diamond', chips: [{ k: 'type', v: 'bracelet', l: 'Bracelet' }, { k: 'stone', v: 'diamond', l: 'Diamond' }], price: null, count: 2 }]; return T.answerSearches(answer); })()"));
        $this->assertSame('', self::drawer('(function () { answer.searches = []; return T.answerSearchesHtml(answer); })()'));
    }
}
