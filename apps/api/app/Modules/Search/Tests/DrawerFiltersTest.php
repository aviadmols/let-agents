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
        // in the sheet a value that would show nothing is not offered, never a greyed "(0)" (Aviad, 18.11); the values
        // chosen stay, each in its place, so they can be taken off
        $this->assertSame([false, 0, true, true], self::drawer("(function () { var x = turn('', [1, 2, 6], { f: { 2000: true, 2003: true } }); var fc = T.facetChips(x, 0); return [fc.codes.indexOf(2001) >= 0, fc.counts[2001], fc.codes.indexOf(2000) >= 0, fc.codes.indexOf(2003) >= 0]; })()"));
    }

    public function test_values_a_product_holds_several_of_go_together_and_the_counts_are_what_pressing_shows(): void
    {
        // Rocks, 19.11: "פנינה (3)" beside a chosen "יהלום" showed 42, most with no pearl. Stones: a ring holds several, so two
        // chosen are a ring with both. Metal: one a product, so two chosen are either. Each count is what the press shows.
        $jewels = <<<'JS'
            S.facets = [
              { k: 'stone', l: 'אבן', v: [{ k: 'diamond', l: 'יהלום' }, { k: 'pearl', l: 'פנינה' }, { k: 'ruby', l: 'רובי' }] },
              { k: 'metal', l: 'מתכת', v: [{ k: 'yellow', l: 'זהב צהוב' }, { k: 'white', l: 'זהב לבן' }] }
            ];
            S.records = {};
            [[1, [0, 1, 1000]], [2, [0, 1000]], [3, [1, 1001]], [4, [0, 2, 1001]], [5, [0, 1001]], [6, [2, 1000]]].forEach(function (p) {
              S.records['p:' + p[0]] = { id: 'p:' + p[0], t: 'product', title: 'טבעת ' + p[0], s: 1, kw: '', f: p[1] };
            });
            var all = function () { return Object.keys(S.records).map(function (id) { return S.records[id]; }); };
            var ids = function (x) { return T.filtered(x).map(function (r) { return r.id; }); };
            JS;
        $out = self::drawer('(function () { '.$jewels.
            "var both = turn('טבעת', [1, 2, 3, 4, 5, 6], { f: { 0: true, 1: true } });".
            "var one = turn('טבעת', [1, 2, 3, 4, 5, 6], { f: { 0: true } });".
            'var w = T.wouldCounts(one, all());'.
            "var metals = turn('טבעת', [1, 2, 3, 4, 5, 6], { f: { 1000: true } });".
            'var wm = T.wouldCounts(metals, all());'.
            "var anyMetal = turn('טבעת', [1, 2, 3, 4, 5, 6], { f: { 1000: true, 1001: true } });".
            'return [ids(both), w.counts[1], ids(one).length, wm.counts[1001], ids(anyMetal).length]; })()');

        $this->assertSame(['p:1'], $out[0], 'diamond and pearl: the ring with both');
        $this->assertSame(1, $out[1], 'beside a chosen diamond, pearl counts what pressing it shows');
        $this->assertSame(4, $out[2]);
        $this->assertSame(6, $out[3], 'a second metal counts the rings of either, as pressing it shows');
        $this->assertSame(6, $out[4]);
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

    public function test_the_exact_match_comes_first_with_the_results_under_it_and_a_word_chip_starts_with_a_capital(): void
    {
        // as Aviad asked, 16.11: the exact match on top, and the results as always under it, that product a card among them
        $html = self::drawer("(function () { var x = turn('Gold Ring', [8], {}); x.single = '8'; return T.resultsHtml(x); })()");
        $this->assertSame(1, substr_count($html, 'ls-exact__tag'));
        $this->assertStringContainsString('ls-grid', $html);
        $this->assertSame(1, preg_match_all('/class="ls-card[" ]/', $html), 'the exact match as a card too, under it');
        $this->assertLessThan(strpos($html, 'ls-grid'), strpos($html, 'ls-exact__tag'), 'the exact match first');
        $this->assertSame(['Boots', 'נעליים', 'Old skool'], self::drawer("[T.capital('boots'), T.capital('נעליים'), T.capital('old skool')]"));
    }

    public function test_the_products_an_answer_shows_offer_their_similar_items(): void
    {
        // as Aviad asked, 16.11: the books an answer recommends, and the row under it, a press away from their like
        $this->assertStringContainsString('data-similar="1"', self::drawer("T.similarLink(S.records['p:1'])"));
        $this->assertSame('', self::drawer("(function () { S.records['p:40'] = { id: 'p:40', t: 'product', title: 'Gift Card', ns: 1 }; return T.similarLink(S.records['p:40']); })()"), 'none where it has no like of its kind');
        $this->assertSame('', self::drawer("T.similarLink({ id: 'p:999', title: 'not in the index' })"));
        $row = self::drawer("T.miniRow([{ id: 'p:1' }, { id: 'p:2' }], true)");
        $this->assertSame(2, substr_count($row, 'data-similar='), 'each product of the row under an answer');
    }

    public function test_a_price_asked_for_is_a_filter_shown_and_never_a_word_missing(): void
    {
        // Rocks QA, 16.11: "שרשרת זהב עד 1000 שקל" said "without 'שקל'", its price applied, and showed no sign of the price
        $note = self::drawer("(function () { S.records['p:50'] = { id: 'p:50', t: 'product', title: 'שרשרת זהב 14K', s: 1, kw: 'שרשרת זהב' }; S.records['p:51'] = { id: 'p:51', t: 'product', title: 'שרשרת זהב לבן', s: 1, kw: 'שרשרת זהב' }; var x = turn('שרשרת זהב עד 1000 שקל', [50, 51], {}); x.unknown = []; return T.noteHtml(x); })()");
        $this->assertStringNotContainsString('שקל', $note);
        $this->assertStringContainsString('Up to ₪1,000', self::drawer("(function () { S.config = {}; var x = turn('שרשרת זהב עד 1000 שקל', [], {}); x.price = { min: null, max: 1000, rest: 'שרשרת זהב' }; return T.activeRow(x); })()"));
        $this->assertStringContainsString('data-chip="price"', self::drawer("(function () { var x = turn('שרשרת עד 500', [], {}); x.price = { min: 100, max: 500, rest: 'שרשרת' }; return T.activeRow(x); })()"));
    }

    public function test_a_code_typed_shows_its_product_alone(): void
    {
        // Rocks QA, 16.11: the SKU's ring, then two bracelets
        $html = self::drawer("(function () { var x = turn('61574', [8, 9, 1], {}); x.single = '8'; x.singleBy = 'sku'; return T.resultsHtml(x); })()");
        $this->assertSame(1, substr_count($html, 'ls-exact__tag'));
        $this->assertSame(1, preg_match_all('/class="ls-card[" ]/', $html), 'that product alone in the grid, not the bracelets after it');
        $this->assertSame(2, substr_count($html, 'data-after="8"'), 'the code\x27s product, on top and as its card');
        $this->assertGreaterThan(1, preg_match_all('/class="ls-card[" ]/', self::drawer("(function () { var x = turn('Gold Ring', [8, 9, 1], {}); x.single = '8'; x.singleBy = 'title'; return T.resultsHtml(x); })()")), 'a name still shows what else holds it');
    }

    public function test_a_value_that_holds_others_of_its_filter_says_it_is_all_of_them(): void
    {
        // QA, 16.11: "זהב (1125)" beside "זהב צהוב" and "זהב לבן" looked like one more kind of gold, its count never moving
        $labels = self::drawer("(function () { var f = { k: 'metal', l: 'Metal', v: [{ k: 'g', l: 'זהב' }, { k: 'y', l: 'זהב צהוב' }, { k: 'w', l: 'זהב לבן' }, { k: 's', l: 'כסף' }] }; return f.v.map(function (v) { return T.asWhole(f, v, v.l); }); })()");
        $this->assertSame(['זהב (all kinds)', 'זהב צהוב', 'זהב לבן', 'כסף'], $labels);
    }

    public function test_a_word_written_as_it_sounds_is_said_by_what_sounds_the_same(): void
    {
        // Miss Nori, 16.11: "מחנסים אדומים" showed the red trousers and said "מחנסים is not on the site"
        $left = self::drawer("(function () { var list = [{ title: 'שורטס הארט | בז/אדום', kw: 'מכנסיים קצרים אדום מכנסי מכנס' }, { title: 'מכנסי מיה | בורדו', kw: 'מכנסיים בורדו' }]; return [T.unsaid('מחנסים אדומים', list, [], []), T.unsaid('מכנסיים ירוקים', list, [], [])]; })()");
        $this->assertSame([[], ['ירוקים']], $left, 'ח as כ is the same word; a colour none says is still said missing');
    }

    public function test_what_is_drawn_on_a_product_is_said_by_it(): void
    {
        // Me369, 18.11: "dress MOON" showed the dress whose pocket reads "FLY ME TO THE MOON" and said "without moon"
        $left = self::drawer("(function () { var list = [{ title: 'Nova Kimono Magic Dress', kw: 'dress kimono black', dw: 'moon and stars sun butterfly' }]; return [T.unsaid('dress moon', list, [], []), T.unsaid('dress unicorn', list, [], [])]; })()");
        $this->assertSame([[], ['unicorn']], $left, 'the moon drawn on it is said; a unicorn none shows is still missing');
    }

    public function test_a_product_its_page_says_is_sold_out_is_drawn_sold_out_and_left_out_of_in_stock(): void
    {
        // Shopify review, 18.11: the admin counted "The 3p Fulfilled Snowboard" in stock, its page said "Sold out"; with
        // "In stock" on it was shown, offered to add, and the press only opened its page
        $got = self::drawer("(function () { S.records['p:20'] = P(20, 'The 3p Fulfilled Snowboard', { buy: 1, url: 'https://s.test/products/3p' }); S.records['p:21'] = P(21, 'The Complete Snowboard', { buy: 1 });
            S.products['20'] = { is_in_stock: false }; S.products['21'] = { is_in_stock: true }; T.liveStock(['p:20', 'p:21']);
            var x = turn('snowboard', [20, 21], { stock: true });
            var out = T.buyBtn(S.records['p:20'], '');
            return [titles(x), out.indexOf('data-add') < 0 && /data-similar|disabled/.test(out), T.buyBtn(S.records['p:21'], '').indexOf('data-add') >= 0]; })()");
        $this->assertSame([['The Complete Snowboard'], true, true], $got, 'out of "In stock"; before any press never offered to add (its like in stock instead, or sold out); the one in stock still adds');
    }

    public function test_every_consent_to_keep_details_links_the_stores_privacy_policy_when_it_is_known(): void
    {
        // Shopify review, 18.11: the form took a name and a phone or an email with a consent box, and no privacy policy beside it
        $got = self::drawer("(function () { var none = T.privacyLink(); S.config.privacyUrl = 'https://s.test/policies/privacy-policy'; var link = T.privacyLink(); S.config.privacyUrl = 'javascript:alert(1)'; return [none, link, T.privacyLink()]; })()");
        $this->assertSame('', $got[0], 'none known, none linked');
        $this->assertStringContainsString('href="https://s.test/policies/privacy-policy"', $got[1]);
        $this->assertStringContainsString('Privacy policy', $got[1]);
        $this->assertStringContainsString('target="_blank"', $got[1], 'opens apart: the form keeps what was typed');
        $this->assertSame('', $got[2], 'only a web address');
    }

    public function test_a_word_read_in_the_stores_language_is_said_by_what_says_its_reading(): void
    {
        // Rocks QA, 16.11: "gold hoop earrings" over the hoops was told "without hoop"
        $note = self::drawer("(function () { S.records['p:60'] = { id: 'p:60', t: 'product', title: 'עגילי חישוק זהב', s: 1, kw: 'עגילים חישוק זהב' }; S.records['p:61'] = { id: 'p:61', t: 'product', title: 'עגילי חישוק זהב לבן', s: 1, kw: 'עגילים חישוק זהב' }; var x = turn('gold hoop earrings', [60, 61], {}); x.unknown = []; x.near = [{ word: 'hoop', term: 'חישוק' }, { word: 'gold', term: 'זהב' }, { word: 'earrings', term: 'עגילים' }]; return T.noteHtml(x); })()");
        $this->assertStringNotContainsString('hoop', $note);

        // ...unless its reading is another word of the search already: "שעון רולקס", "רולקס" read as "שעון", is no Rolex
        // (QA, 16.11: every watch was shown with nothing said)
        $rolex = self::drawer("(function () { S.records['p:62'] = { id: 'p:62', t: 'product', title: 'שעון יד וינטג׳', s: 1, kw: 'שעון' }; S.records['p:63'] = { id: 'p:63', t: 'product', title: 'שעון כיס', s: 1, kw: 'שעון' }; var x = turn('שעון רולקס', [62, 63], {}); x.unknown = []; x.held = 2; x.near = [{ word: 'רולקס', term: 'שעון' }]; return T.noteHtml(x); })()");
        $this->assertStringContainsString('רולקס', $rolex);
    }

    public function test_what_people_ask_here_is_only_in_the_sites_language(): void
    {
        // Jean Rachel, 16.11: a Hebrew question kept from when the demo spoke Hebrew, among its English ones
        $seed = "[['a:1','what is your return policy?'],['a:2','אפשר להחזיר טבעת אם המידה לא מתאימה?'],['a:3','Do you ship abroad?'],['a:4','יש משלוח ל-Eilat?']].forEach(function (x) { S.records[x[0]] = { id: x[0], t: 'answer', title: x[1], ans: 'yes', ak: 'store', an: 2, w: 1 }; });";
        $this->assertSame(['what is your return policy?', 'Do you ship abroad?'], self::drawer("(function () { {$seed} return T.peopleAsk().map(function (r) { return r.title; }); })()"));
        // and a Hebrew site its Hebrew ones, a Latin name inside one included
        $this->assertSame(['אפשר להחזיר טבעת אם המידה לא מתאימה?', 'יש משלוח ל-Eilat?'], self::drawer("(function () { LANG_SET('he'); {$seed} return T.peopleAsk().map(function (r) { return r.title; }); })()"));
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
