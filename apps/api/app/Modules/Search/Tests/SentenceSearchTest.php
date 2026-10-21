<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
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

    public function test_a_value_read_from_the_words_is_what_a_product_carries(): void
    {
        $found = $this->search('טבעת זהב צהוב');

        $this->assertContains('1', $found['ids']);
        $this->assertNotContains('4', $found['ids'], 'it carries white gold; its words mention a yellow-gold rim');
        $this->assertNotContains('2', $found['ids']);
    }

    public function test_a_word_inside_another_word_is_no_product_shown_alone(): void
    {
        $search = fn (string $query): ?array => app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, false)['single'];

        $this->assertNull($search('עצים'), '"עצים" inside the earring\'s "נעצים" (studs): not shown alone as if it were asked for');
        $this->assertSame(['id' => 'p:6', 'by' => 'words'], $search('עגיל בודד'), 'the only product that says the words, as words');
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
