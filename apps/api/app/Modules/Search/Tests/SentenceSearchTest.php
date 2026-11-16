<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\SpellingProductsNamed;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shopper writes a wish, not a query: "אני רוצה לקנות מתנה לאשתי טבעת יהלום עד 2500" is diamond rings up to 2500,
 * never nothing because no product holds "אני רוצה". And a value read from the words is what a product carries: a ring
 * of white gold whose words mention yellow gold is no yellow-gold ring (Rocks and Gold, 2026-10-21).
 */
final class SentenceSearchTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'he']);
        $facets = [
            ['k' => 'type', 'l' => 'סוג מוצר', 'v' => [['k' => 'ring', 'l' => 'טבעת'], ['k' => 'pendant', 'l' => 'תליון']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'yellow_gold', 'l' => 'זהב צהוב'], ['k' => 'white_gold', 'l' => 'זהב לבן']]],
            ['k' => 'stone', 'l' => 'אבן', 'v' => [['k' => 'diamond', 'l' => 'יהלום']]],
        ];
        $items = array_map(fn (array $p): array => ['id' => 'p:'.$p[0], 't' => 'product', 'title' => $p[1], 'kw' => $p[2], 'url' => 'https://rings.test/'.$p[0], 's' => 1, 'pr' => $p[3], 'buy' => 1, 'f' => $p[4]], [
            ['1', 'טבעת זהב צהוב משובצת יהלום', 'טבעות יהלום', 1800, [0, 1000, 2000]],
            ['2', 'טבעת סוליטר משובצת יהלום', 'טבעות זהב לבן', 2400, [0, 1001, 2000]],
            ['3', 'טבעת יהלומים יוקרתית', 'טבעות זהב צהוב', 5000, [0, 1000, 2000]],
            ['4', 'טבעת זהב לבן עדינה', 'טבעות פס זהב צהוב בשוליים', 900, [0, 1001]],
            ['5', 'תליון משובץ יהלומים', 'תליונים סגר טבעת', 2300, [1, 1000, 2000]],
            ['6', 'עגיל בודד משובץ יהלום', 'עגילים עגיל נעצים', 1100, [2000]],
            // its title says yellow gold, its metal filter lists only the white of its two tones
            ['7', 'טבעת שני גוונים זהב צהוב וזהב לבן', 'טבעות', 1500, [0, 1001]],
        ]);
        $json = (string) json_encode(['items' => $items, 'facets' => $facets, 'actions' => []], JSON_UNESCAPED_UNICODE);
        app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => md5($json), 'items' => $json, 'counts' => [], 'built_at' => now()]));
    }

    public function test_a_wish_is_searched_by_what_it_asks_for(): void
    {
        $found = $this->search('אני רוצה לקנות מתנה לאשתי טבעת יהלום עד 2500 ש"ח');

        $this->assertSame([], $found['unknown'], '"לאשתי" says whom it is for: nothing to tell the shopper is missing');
        $this->assertContains('1', $found['ids']);
        $this->assertContains('2', $found['ids']);
        $this->assertNotContains('3', $found['ids'], 'above the price written');
        $this->assertSame($found['ids'], $this->search('טבעת יהלום עד 2500')['ids'], 'the same as the words it asks for');
    }

    public function test_a_sentence_read_as_what_it_asks_for_is_not_held_as_typed(): void
    {
        // Rocks QA, 16.11: "מחפש מתנה לאשתי ליום נישואין, משהו עדין עד 2000 שקל" was read as held, and code answered "the
        // cheapest in the price" with one ring; "מתנה לחברה שלי ליום הולדת" names no product, a choice from the range
        $named = app(SpellingProductsNamed::class);

        $this->assertFalse($named->holds($this->shop->id, 'מחפש מתנה לאשתי ליום נישואין, משהו עדין'));
        $this->assertTrue($named->namesNothing($this->shop->id, 'אני רוצה לקנות לחברה שלי מתנה ליום הולדת'));
        $this->assertFalse($named->holds($this->shop->id, 'אני רוצה לקנות מתנה לאשתי טבעת יהלום'), 'no ring says "מתנה לאשתי": not held as typed');
        $this->assertFalse($named->namesNothing($this->shop->id, 'אני רוצה לקנות מתנה לאשתי טבעת יהלום'), 'but it names diamond rings');
        $this->assertTrue($named->holds($this->shop->id, 'טבעת יהלום'));
        $this->assertFalse($named->namesNothing($this->shop->id, 'טבעת יהלום'));
    }

    public function test_a_colour_still_being_typed_finds_what_its_whole_word_does(): void
    {
        // Miss Nori, 16.11: "מכנסים שחורי" 14, "מכנסים שחורים" 12; "חולצה כחו" 14, "חולצה כחולה" 16
        $this->assertSame($this->search('טבעת זהב צהוב')['ids'], $this->search('טבעת זהב צהו')['ids']);
        // two letters are no start of a word yet
        $this->assertNotSame($this->search('טבעת זהב צהוב')['ids'], $this->search('טבעת זהב צה')['ids']);
        // in English as in Hebrew; and never where the start is also another word's
        $complete = new \ReflectionMethod(SearchCatalog::class, 'colourCompleted');
        $this->assertSame('ring blue', $complete->invoke(null, ['list' => ['ring', 'blue', 'blues']], 'ring blu'));
        $this->assertSame('ring blu', $complete->invoke(null, ['list' => ['ring', 'blue', 'blush']], 'ring blu'), '"blush" is no colour word here');
        $this->assertSame('ring re', $complete->invoke(null, ['list' => ['ring', 'red']], 'ring re'));
    }

    public function test_a_value_read_from_the_words_is_what_a_product_carries(): void
    {
        $found = $this->search('טבעת זהב צהוב');

        $this->assertContains('1', $found['ids']);
        $this->assertNotContains('4', $found['ids'], 'it carries white gold; its words mention a yellow-gold rim');
        // its own title saying the value is it, whatever else its filter lists, as the drawer reads it (Rocks QA, 16.11: the
        // corundum rings titled "כחול" left out once "כחול" was read as the stone colour filter: 132, then 76)
        $this->assertContains('7', $found['ids']);
        $this->assertNotContains('2', $found['ids']);
    }

    public function test_a_word_inside_another_word_is_no_product_shown_alone(): void
    {
        $search = fn (string $query): ?array => app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, false)['single'];

        $this->assertNull($search('עצים'), '"עצים" inside the earring\'s "נעצים" (studs): not shown alone as if it were asked for');
        $this->assertSame(['id' => 'p:6', 'by' => 'words'], $search('עגיל בודד'), 'the only product that says the words, as words');
        // and by its own name: words its other words hold (a collection, a tag) find it, never as the exact match (Miss Nori,
        // 16.11: "בגד ים" showed a T-shirt alone, its only tie the collection "בגדי ים וחוף")
        $this->assertNull($search('עגיל נעצים'), '"נעצים" is in its words, not its name');
    }

    public function test_one_letter_is_not_a_search(): void
    {
        // Seen on Sela: "ס" found the one book whose author is "תומס ס. טיילור" and showed it as the exact match.
        $search = fn (string $query): array => app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, false);

        foreach (['ע', ' ע ', 'ע.', '7'] as $typed) {
            $found = $search($typed);
            $this->assertNull($found['single'] ?? null, "\"{$typed}\": no exact match");
            $this->assertSame([], $found['groups']['product'] ?? [], "\"{$typed}\": no results yet");
        }
        $this->assertSame(['id' => 'p:6', 'by' => 'words'], $search('עגיל בודד')['single'], 'two words, as before');
    }

    public function test_what_a_sentence_means_keeps_a_product_word_the_store_lacks(): void
    {
        $index = app(TenantContext::class)->run($this->shop->id, fn () => LoadedIndex::for($this->shop->id));

        $this->assertSame('טבעת יהלומ', HebrewSearch::meant($index['engine'], 'אני רוצה לקנות מתנה לאשתי טבעת יהלום'));
        $this->assertSame('חולצה', HebrewSearch::meant($index['engine'], 'אני מחפש חולצה'), 'the shopper is then told "חולצה" is not here');
    }

    /** @return array{ids: list<string>, unknown: list<string>} */
    private function search(string $query): array
    {
        $result = app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, true);

        return ['ids' => array_map(fn (array $r): string => substr((string) $r['id'], 2), $result['groups']['product']), 'unknown' => $result['unknown'] ?? []];
    }
}
