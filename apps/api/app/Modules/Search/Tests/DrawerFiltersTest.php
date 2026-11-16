<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Actions\BuildSearchIndex;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The drawer's own reading of a search, run in Node over a small store (fixtures/drawer-harness.js): whom and what a
 * search names, read from a product's name and picture as well as the store's tags, and ordered by colour; the counts
 * beside the filters the same as what is shown; the sentence when part of the search is not there; the source line;
 * the pictures' size. Naot, Jean Rachel and Vans QA, 16.11.
 */
class DrawerFiltersTest extends TestCase
{
    /** A Naot-like store: colour 0-2, whom 1000-1001, the store's tags 2000-2003. */
    private const STORE = <<<'JS'
        var S = T.S;
        S.facets = [
          { k: 'variant_color', l: 'Colour', v: [{ k: 'black', l: 'Black' }, { k: 'white', l: 'White' }, { k: 'grey', l: 'Grey' }] },
          { k: 'audience', l: 'For', v: [{ k: 'women', l: 'Women' }, { k: 'men', l: 'Men' }] },
          { k: 'store_tags', l: 'Tags', v: [{ k: 'tag:sandals', l: 'Sandals' }, { k: 'tag:apma', l: 'APMA Accepted' }, { k: 'tag:ring', l: 'Ring' }, { k: 'tag:organic', l: 'Organic' }] }
        ];
        var P = function (id, title, o) { return Object.assign({ id: 'p:' + id, t: 'product', title: title, s: 1, kw: '', f: [] }, o); };
        [
          P(1, 'Arthur', { f: [0, 1001, 2000, 2003], tg: [['sandal', 0.9]], v: [[[0], 1]] }),
          P(2, 'Mikael', { f: [1001, 2001], tg: [['sandal', 0.9], ['slide sandal', 0.9]] }),
          P(3, 'Santa Barbara Mens', { f: [0, 1001], tg: [["men's slide sandal", 0.9]] }),
          P(4, 'Jeff', { f: [0], kw: "slide sandal men's sandal men", tg: [['slide sandal', 0.9]] }),
          P(5, 'Fiord', { f: [1001, 2001], kw: 'Men > Sandals > Scandinavian Collection', tg: [['clogs', 0.9], ['slip-on shoes', 0.9]] }),
          P(6, 'Lily', { f: [1000, 2000], tg: [['sandal', 0.9]] }),
          P(7, 'Martin', { f: [0, 2, 1001, 2000], tg: [['sandals', 0.9]], v: [[[0, 2], 1]] }),
          P(8, 'Gold Ring', { f: [2002], tg: [['ring', 0.9]] }),
          P(9, 'Bar Necklace', { f: [2003], kw: 'sterling silver spring ring clasp', tg: [['necklace', 0.9]] })
        ].forEach(function (r) { S.records[r.id] = r; });
        var turn = function (q, ids, c) { var x = { id: 't', q: q, ids: ids.map(function (i) { return 'p:' + i; }), c: Object.assign({ f: {}, sort: '', stock: false, sale: false, no: {}, auto: {}, at: {}, words: {}, suggest: {}, picked: {} }, c) }; return x; };
        var titles = function (x) { return T.filtered(x).map(function (r) { return r.title; }); };
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

    public function test_whom_and_kind_read_from_the_words_hold_by_a_products_name_and_picture_not_one_tag(): void
    {
        // "sandals for men": Men and the store's tag Sandals, read from the words. Before, a sandal without the tag but
        // with another (Mikael), or none (Santa Barbara Mens, Jeff "sandal" for "Sandals"), and one not filed for men (Jeff)
        // were left out: 6 shown where Similar showed 9
        $this->assertSame(['Arthur', 'Mikael', 'Santa Barbara Mens', 'Jeff', 'Martin'], self::drawer("titles(turn('sandals for men', [1, 2, 3, 4, 5, 6, 7], { auto: { 1001: true, 2000: true } }))"));
        // a clog the store files under "Men > Sandals" is not a sandal by its picture (Fiord), nor a necklace whose
        // description says "spring ring clasp" a ring
        $this->assertSame(['Gold Ring'], self::drawer("titles(turn('ring', [8, 9], { auto: { 2002: true } }))"));
    }

    public function test_a_colour_read_from_the_words_shows_what_is_that_colour_alone_first(): void
    {
        // "black sandals": Martin is black and grey ("Oily Coal Combo"), after every sandal in black alone
        $shown = self::drawer("titles(turn('black sandals', [7, 1, 3, 4], { auto: { 0: true, 2000: true } }))");
        $this->assertSame('Martin', end($shown));
        $this->assertCount(4, $shown);
    }

    public function test_the_counts_beside_the_filters_are_what_pressing_them_shows(): void
    {
        // a value read from the words is counted as it is applied: Men holds Jeff by his words, so its count is what is shown
        $this->assertSame([true, 4], self::drawer("(function () { var x = turn('sandals for men', [1, 2, 3, 4, 6], { auto: { 1001: true } }); var w = T.wouldCounts(x, x.ids.map(function (i) { return S.records[i]; })); return [w.counts[1001] === T.filtered(x).length, w.counts[1001]]; })()"));
        // the store's tags narrow together, and each is counted within what the others show: Sandals chosen, Organic
        // counts the one sandal that is organic, never every organic product; chosen, the two together show that one
        $this->assertSame([1, ['Arthur']], self::drawer("(function () { var x = turn('', [1, 6, 7, 9], { f: { 2000: true } }); var w = T.wouldCounts(x, x.ids.map(function (i) { return S.records[i]; })); var y = turn('', [1, 6, 7, 9], { f: { 2000: true, 2003: true } }); return [w.counts[2003], titles(y)]; })()"));
        // in the sheet a value that would show nothing stays in its place, counted 0 (greyed), never taken out from under
        // the finger, nor a new one brought in
        $this->assertSame([true, 0], self::drawer("(function () { var x = turn('', [1, 2, 6], { f: { 2000: true, 2003: true } }); var fc = T.facetChips(x, 0); return [fc.codes.indexOf(2001) >= 0, fc.counts[2001]]; })()"));
    }

    public function test_a_sets_count_in_the_answer_is_the_cards_it_shows(): void
    {
        // the server counts each version of a model, the grid one card a model: "Gold necklaces (3)" over two cards
        $this->assertSame(['Gold necklaces (2):', 'Rings (2):'], self::drawer(
            "(function () { [P(30, 'Bar Necklace', { cf: 'm1' }), P(31, 'Bar Necklace', { cf: 'm1' }), P(32, 'Leaf Necklace', {})].forEach(function (r) { S.records[r.id] = r; }); var a = turn('gold necklace', [30, 31, 32], {}); a.answer = 'Gold necklaces (3):'; var b = turn('ring', [30, 32], {}); b.answer = 'Rings (2):'; return [T.setCounted(a), T.setCounted(b)]; })()"
        ));
    }

    public function test_articles_and_pages_found_with_no_product_are_shown_not_nothing_found(): void
    {
        // Profimex, 16.11: 49 posts for "השקעה בנדל״ן", and the drawer showed only the card that asks the AI
        $html = self::drawer("(function () { var x = turn('השקעה בנדלן', [], {}); x.content = [{ id: 'c:1', title: 'השקעה בנדל״ן – ניתוח מקרה', url: 'https://site.test/case/', kind: 'post' }, { id: 'c:2', title: 'אודותינו', url: 'https://site.test/about/', kind: 'page' }]; return T.resultsHtml(x); })()");
        $this->assertStringContainsString('השקעה בנדל״ן – ניתוח מקרה', $html);
        $this->assertStringContainsString('אודותינו', $html);
        $this->assertStringNotContainsString('data-empty', $html, 'nothing found is not said over what was found, and Enter keeps the search');
        $this->assertStringContainsString('data-empty', self::drawer("(function () { var x = turn('xyz', [], {}); x.content = []; return T.resultsHtml(x); })()"), 'and nothing at all still offers the AI');
    }

    public function test_only_this_openings_answers_are_already_told(): void
    {
        // QA, 16.11: "what is your return policy?" answered "We also offer exchanges within 60 days." alone: the same
        // question's answer of an earlier opening, hidden behind the dashed line, was sent as told. One hidden as the
        // shopper types the next question is this opening's still
        $this->assertSame(['typing hid it', 'shipping?'], self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'what is your return policy?', answer: 'Returns within 30 days.', prev: true, earlier: true }, { id: 'x', kind: 'answer', q: 'typing hid it', answer: 'Yes.', prev: true }, { id: 'b', kind: 'answer', q: 'shipping?', answer: 'Shipping takes 3 days.' }, { id: 'c', kind: 'answer', q: 'now', loading: true }]; return T.toldOnScreen('c').map(function (x) { return x.q; }); })()"));
    }

    public function test_a_question_after_an_answer_goes_on_with_its_conversation_unless_it_is_of_an_earlier_opening(): void
    {
        // Naot QA, 16.11: "ומה עם מוצרים במבצע?" typed after the men's sandals' answer: typing hid the answer, and the
        // question went alone ("9 on sale now" of the whole store). Hidden as one types, it is gone on with
        $this->assertSame([['q' => 'Which sandals do you have for men?', 'a' => "Men's sandals (11):"]], self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'Which sandals do you have for men?', answer: \"Men's sandals (11):\", prev: true }]; return T.conversationGoingOn(); })()"));
        // its own conversation with it, the last three
        $this->assertCount(3, self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'q3', answer: 'a3', thread: [{ q: 'q1', a: 'a1' }, { q: 'q2', a: 'a2' }] }]; return T.conversationGoingOn(); })()"));
        // an earlier opening's answer, a search in between, an answer still loading: a question of its own
        $this->assertNull(self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'old', answer: 'old answer', prev: true, earlier: true }]; return T.conversationGoingOn(); })()"));
        $this->assertNull(self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'q', answer: 'a' }, { id: 'b', kind: 'results', q: 'boots', ids: [] }]; return T.conversationGoingOn(); })()"));
        $this->assertNull(self::drawer("(function () { S.turns = [{ id: 'a', kind: 'answer', q: 'q', loading: true }]; return T.conversationGoingOn(); })()"));
    }

    public function test_a_card_writes_a_price_exactly_and_tells_the_assistant_how(): void
    {
        // Naot QA, 16.11: "AUD 202.46" in the answer, "$202" on its card. The card exact, two decimals unless whole; the
        // assistant told the currency and the sign the cards write
        $this->assertSame(['$202.46', '$99.50', '$250', ['currency' => 'USD', 'currency_sign' => '$', 'sign_after' => false]], self::drawer("(function () { S.config = { currency: 'USD' }; return [T.money(202.46), T.money(99.5), T.money(250), T.priceLike()]; })()"));
        $this->assertSame(['₪1,250', ['currency' => 'ILS', 'currency_sign' => '₪', 'sign_after' => false]], self::drawer('(function () { S.config = {}; return [T.money(1250), T.priceLike()]; })()'));
        $this->assertSame(['currency' => 'EUR', 'currency_sign' => '€', 'sign_after' => true], self::drawer("(function () { S.config = { currency: 'EUR' }; S.cur = { prefix: '', suffix: ' €' }; return T.priceLike(); })()"));
    }

    public function test_the_exact_match_is_shown_once_and_a_word_chip_starts_with_a_capital(): void
    {
        $html = self::drawer("(function () { var x = turn('Gold Ring', [8], {}); x.single = '8'; return T.resultsHtml(x); })()");
        $this->assertSame(1, substr_count($html, 'ls-exact__tag'));
        $this->assertSame(0, substr_count($html, 'class="ls-card'), 'the exact match, not again as a card in a grid under it');
        $this->assertStringNotContainsString('ls-grid', $html);
        $this->assertSame(['Boots', 'נעליים', 'Old skool'], self::drawer("[T.capital('boots'), T.capital('נעליים'), T.capital('old skool')]"));
    }

    public function test_a_tag_all_in_capitals_or_small_letters_is_shown_in_title_case_and_a_brand_as_it_is(): void
    {
        $this->assertSame(['Sterling Silver', 'Mens Bracelets', 'Big-Bold', 'APMA Accepted', 'VANS', 'צמידים'], self::drawer(
            "[T.niceLabel({ k: 'store_tags' }, 'STERLING SILVER'), T.niceLabel({ k: 'store_tags' }, 'mens bracelets'), T.niceLabel({ k: 'store_tags' }, 'big-bold'), T.niceLabel({ k: 'store_tags' }, 'APMA Accepted'), T.niceLabel({ k: 'brand' }, 'VANS'), T.niceLabel({ k: 'category' }, 'צמידים')]"
        ));
    }

    public function test_part_of_a_search_not_there_is_said_as_the_search_and_the_words_left_out(): void
    {
        // "Nothing “kids” in “sneakers”" was no sentence: the search as typed, and what is left out of the closest
        $note = self::drawer("(function () { S.records['p:20'] = { id: 'p:20', t: 'product', title: 'Old Skool Sneakers', s: 1, kw: 'sneakers black' }; S.records['p:21'] = { id: 'p:21', t: 'product', title: 'Authentic Sneakers', s: 1, kw: 'sneakers white women' }; var x = turn('sneakers for kids', [20, 21], {}); x.unknown = []; return T.noteHtml(x); })()");
        $this->assertStringContainsString('No “sneakers for kids” right now. Here are the closest matches, without “kids”:', $note);
        $this->assertStringNotContainsString('data-askcard', $note, 'the card that asks the AI comes under the products, not over them');
        $this->assertSame('No “red leather boots” right now. Here are the closest matches, without “red”, “leather”:', self::drawer("T.t('noneSaid', { q: 'red leather boots', gone: ['red', 'leather'].join('”, “') })"));
    }

    public function test_an_answer_that_only_hands_over_to_the_team_shows_no_source(): void
    {
        $this->assertSame([true, true, false, false], self::drawer(
            "[T.handsOver(\"This is one for our team to check. The store's team will be glad to help you.\"), T.handsOver('את זה צריך לבדוק מול הצוות שלנו. צוות החנות ישמח לעזור לכם.'), T.handsOver(\"Returns are free within 30 days. The store's team will be glad to help you.\"), T.handsOver('Shipping takes 3 to 5 days.')]"
        ));
    }

    public function test_a_shopify_picture_is_asked_at_the_size_it_is_shown(): void
    {
        $this->assertSame([
            'https://cdn.shopify.com/s/files/1/x/files/a.jpg?v=17&width=480',
            'https://naot.com.au/cdn/shop/files/a.png?width=480',
            'https://cdn.shopify.com/s/files/a.jpg?width=200',
            'https://gueta.co.il/wp-content/uploads/a.jpg',
        ], self::drawer("[T.sized('https://cdn.shopify.com/s/files/1/x/files/a.jpg?v=17', 480), T.sized('https://naot.com.au/cdn/shop/files/a.png', 480), T.sized('https://cdn.shopify.com/s/files/a.jpg?width=200', 480), T.sized('https://gueta.co.il/wp-content/uploads/a.jpg', 480)]"));
    }

    public function test_the_tags_a_store_keeps_for_its_shop_pages_are_never_a_filter(): void
    {
        $hidden = BuildSearchIndex::hiddenTags(' Gift Guide, Vacation Shop ,,Naot Hero Styles, gift guide');
        $this->assertSame(['gift guide', 'vacation shop', 'naot hero styles'], $hidden);
        $this->assertTrue(BuildSearchIndex::hidden('gift guide', $hidden));
        $this->assertTrue(BuildSearchIndex::hidden('gift guide > for her', $hidden), 'a menu path under one, too');
        $this->assertTrue(BuildSearchIndex::hidden('naot hero styles', $hidden));
        $this->assertFalse(BuildSearchIndex::hidden('gift guidebook', $hidden), 'a word that only starts the same is another tag');
        $this->assertFalse(BuildSearchIndex::hidden('sandals', $hidden));
        $this->assertSame([], BuildSearchIndex::hiddenTags(''));
    }
}
