<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * "מקדחה בצבע שחור" on Gueta showed black hooks and handles: "בצבע" is not a word a black drill
 * holds, so the hooks, holding "בצבע שחור", won. "בצבע" now only says a colour follows; and when
 * no product holds every word, the narrower searches that do find are offered to choose from.
 */
final class FocusSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../resources/search/hebrew-search-engine.js';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
    }

    public function test_a_colour_after_בצבע_is_a_colour_of_the_drill_not_a_word_of_its_own(): void
    {
        $this->catalog([
            '1' => ['מקדחה רוטטת 850W', 'כחול'],
            '2' => ['מקדחה נטענת Makita', 'שחור'],
            '3' => ['קולב כפול מסולסל בצבע שחור', 'פרזול'],
            '4' => ['ידית אחיזה גדולה בצבע שחור', 'פרזול'],
        ]);

        $result = $this->search('מקדחה בצבע שחור');

        $this->assertSame(['2'], array_column($result['groups']['product'], 'external_id'), 'the black drill, not the black hooks');
        $this->assertNull($result['focus'], 'something holds every word: nothing to choose');
    }

    public function test_a_kind_named_with_a_colour_shows_that_kind_only_or_none(): void
    {
        // Vans: "תיק לבן" showed a white keychain, white sneakers and a white T-shirt: the colour holds across kinds,
        // and the store has no white bag. The kind named ("תיק", opening "תיק גב", "תיק צד") holds: none is shown.
        $this->catalog([
            '1' => ['תיק גב Old Skool', 'שחור'],
            '2' => ['תיק צד Lawler', 'שחור'],
            '3' => ['מחזיק מפתחות Micro Old Skool תיק', 'לבן'],
            '4' => ['נעלי סניקרס Authentic', 'לבן'],
            '5' => ['נעלי סניקרס Era', 'שחור'],
        ]);

        $this->assertSame([], array_column($this->search('תיק לבן')['groups']['product'], 'external_id'), 'no white bag: no other white thing instead');
        $black = array_column($this->search('תיק שחור')['groups']['product'], 'external_id');
        sort($black);
        $this->assertSame(['1', '2'], $black, 'the black bags, not the black sneakers');
        $this->assertSame(['4'], array_column($this->search('נעליים לבנות')['groups']['product'], 'external_id'), 'the plural names the kind "נעלי" opens');
    }

    public function test_a_colour_is_one_word_whatever_its_gender(): void
    {
        // Gueta: "מברגה שחורה" showed two handles, holding "שחורה" and "הברגה" (threading), and none
        // of the screwdrivers whose colour is "שחור".
        $this->catalog([
            '1' => ['מברגה נטענת Makita', 'שחור'],
            '2' => ['מברגה רוטטת Einhell', 'כחול'],
            '3' => ['ידית שחורה 152 מ"מ', 'הברגה'],
        ]);

        $found = array_column($this->search('מברגה שחורה')['groups']['product'], 'external_id');

        $this->assertSame('1', $found[0], 'the black screwdriver first');
        $this->assertNotContains('2', $found, 'not the blue one');
        $this->assertSame('1', $this->search('מברגה שחור')['groups']['product'][0]['external_id'], 'and in the masculine too');
    }

    public function test_a_guide_or_a_page_shows_only_when_it_holds_every_word(): void
    {
        $this->catalog(['1' => ['מברגה נטענת Makita', 'שחור']]);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['20' => 'עגלה שחורה לחצר', '21' => 'איך בוחרים מברגה שחורה'] as $id => $title) {
                CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'page', 'external_id' => (string) $id, 'title' => $title,
                    'url' => "https://www.store.test/?p={$id}", 'terms' => [], 'hash' => "h{$id}"]);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $content = array_column($this->search('מברגה שחורה')['groups']['content'], 'external_id');

        $this->assertSame(['21'], $content, 'the guide about black screwdrivers, not the black cart');
    }

    public function test_when_nothing_holds_every_word_the_narrower_searches_are_offered(): void
    {
        $this->catalog([
            '1' => ['מקדחה רוטטת 850W', 'כחול'],
            '2' => ['מקדחה נטענת Einhell', 'אדום'],
            '3' => ['קולב כפול מסולסל בצבע שחור', 'פרזול'],
            '4' => ['ידית אחיזה גדולה בצבע שחור', 'פרזול'],
            '5' => ['כפתור עגול לארון בצבע שחור', 'פרזול'],
        ]);

        $result = $this->search('מקדחה בצבע שחור');

        $this->assertSame([['query' => 'מקדחה', 'count' => 2], ['query' => 'בצבע שחור', 'count' => 3]], $result['focus']['options']);
        $this->assertNull($this->search('מקדחה בצבע שחור עד 500')['focus'], 'with a price, the price narrows');
        $this->assertNull($this->search('איזו מקדחה בצבע שחור?')['focus'], 'a question goes to the assistant');
        $this->assertNull($this->search('מקדחה')['focus'], 'one word: nothing to narrow');
    }

    public function test_the_browser_offers_the_same_choices_as_the_server(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $items = [
            ['id' => 'p:1', 'title' => 'מקדחה רוטטת 850W', 'keywords' => 'כחול'],
            ['id' => 'p:2', 'title' => 'מקדחה נטענת Einhell', 'keywords' => 'אדום'],
            ['id' => 'p:3', 'title' => 'קולב כפול מסולסל בצבע שחור', 'keywords' => 'פרזול'],
            ['id' => 'p:4', 'title' => 'מברגה Makita בגוון ירוק', 'keywords' => 'כלי עבודה'],
            ['id' => 'k:1', 'title' => 'מקדחות', 'keywords' => 'כלי עבודה'],
        ];
        $queries = ['מקדחה בצבע שחור', 'מקדחה שחורה', 'קולב שחור', 'קולב שחורים', 'מברגה בגוון אדום', 'קולב בצבע ירוק', 'מקדחה מקיטה ירוקה', 'קרש'];
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const i=e.build('.json_encode($items, JSON_UNESCAPED_UNICODE).');const out={};'
            .'for(const q of '.json_encode($queries, JSON_UNESCAPED_UNICODE).'){out[q]={focus:e.focus(i,q),hits:e.search(i,q).map(h=>h.id+(h.full?"*":""))};}'
            .'process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);
        $index = HebrewSearch::build($items);

        foreach ($queries as $query) {
            $this->assertSame(HebrewSearch::focus($index, $query), $browser[$query]['focus'], "\"{$query}\": the choices differ");
            $this->assertSame(array_map(fn (array $h): string => $h['id'].($h['full'] ? '*' : ''), HebrewSearch::search($index, $query)), $browser[$query]['hits'], "\"{$query}\": the hits differ");
        }
        $this->assertNotSame([], $browser['מקדחה בצבע שחור']['focus']);
    }

    /** @param  array<string, array{0: string, 1: string}>  $products  id => [title, a word of its attributes] */
    private function catalog(array $products): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($products): void {
            foreach ($products as $id => [$title, $tag]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => [$tag]], 'price' => 100,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        return app(SearchCatalog::class)->handle($this->shop->id, $query, count: false);
    }
}
