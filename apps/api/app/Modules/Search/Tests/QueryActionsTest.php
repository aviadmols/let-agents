<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phrases that are actions, the same in every shop ("הכי זול", "במבצע", "גדול מ-2", "או"), and the
 * shop's own values as filters beside the results, from its approved facts. Read the same way on
 * the server and in the browser.
 */
final class QueryActionsTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../resources/search/hebrew-search-engine.js';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'he']);

        $this->inShop(function (): void {
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'ring', 'label' => ['he' => 'טבעת', 'en' => 'Ring']], ['key' => 'earrings', 'label' => ['he' => 'עגילים', 'en' => 'Earrings']]],
                    'attributes' => [
                        ['key' => 'metal', 'type' => 'enum', 'label' => ['he' => 'מתכת', 'en' => 'Metal'], 'values' => [
                            ['key' => 'yellow_gold', 'label' => ['he' => 'זהב צהוב', 'en' => 'Yellow gold']], ['key' => 'white_gold', 'label' => ['he' => 'זהב לבן', 'en' => 'White gold']]]],
                        ['key' => 'diameter', 'type' => 'number', 'label' => ['he' => 'קוטר', 'en' => 'Diameter'], 'dimension' => 'length', 'min' => 1, 'max' => 100],
                    ]]]);
            $products = [
                ['1', 'טבעת יהלום קלאסית', 900, true, false, 'ring', 'yellow_gold', 18],
                ['2', 'טבעת יהלום וינטג', 500, true, true, 'ring', 'white_gold', 22],
                ['3', 'טבעת יהלום עדינה', 300, false, false, 'ring', 'yellow_gold', 16],
                ['4', 'עגילי יהלום', 700, true, true, 'earrings', 'white_gold', null],
            ];
            foreach ($products as [$id, $title, $price, $inStock, $sale, $type, $metal, $diameter]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => $price, 'in_stock' => $inStock, 'on_sale' => $sale, 'purchasable' => true, 'hash' => "h{$id}",
                    'payload' => ['created_at' => "2026-0{$id}-01T10:00:00Z"]]);
                $facts = [[FactKind::Type, 'type', $type, null], [FactKind::Choice, 'metal', $metal, null]];
                if ($diameter !== null) {
                    $facts[] = [FactKind::Spec, 'diameter', null, $diameter];
                }
                foreach ($facts as $i => [$kind, $key, $text, $number]) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key,
                        'value_text' => $text, 'value_number' => $number, 'unit' => $number === null ? null : 'mm', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "f{$id}{$i}"]);
                }
            }
        });
    }

    public function test_the_index_holds_each_products_filters_and_the_phrases_this_shop_can_act_on(): void
    {
        Settings::set('search.size_attribute', 'diameter', $this->shop->id);
        $data = $this->build();

        $this->assertSame(['type', 'metal'], array_column($data['facets'], 'k'));
        $this->assertSame(['טבעת', 'עגילים'], array_column($data['facets'][0]['v'], 'l'));
        $this->assertSame(['k' => 'diameter', 'l' => 'קוטר'], $data['size']);
        $ring = collect($data['items'])->firstWhere('id', 'p:2');
        $this->assertSame([0, 1001], $ring['f'], 'ring (type 0), white gold (metal 1)');
        // its title never says "זהב לבן": the name of what its facts say it is finds it all the same
        $this->assertStringContainsString('זהב לבן', $ring['kw']);
        $this->assertEquals(22, $ring['z']);
        $this->assertSame(1, $ring['sl']);
        $this->assertSame(20260201, $ring['d']);
        $this->assertContains('big', array_column($data['actions'], 'id'));

        Settings::set('search.size_attribute', '', $this->shop->id);
        $this->assertNotContains('big', array_column($this->build()['actions'], 'id'), 'no size here: "גדול" stays a word');
        $this->assertContains('cheapest', array_column($this->build()['actions'], 'id'));
    }

    public function test_the_server_sorts_filters_and_searches_either_side(): void
    {
        Settings::set('search.size_attribute', 'diameter', $this->shop->id);
        $this->build();
        $ids = fn (string $query): array => array_column(app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, false)['groups']['product'], 'external_id');

        $this->assertSame(['2', '1', '3'], $ids('טבעת יהלום הכי זולה'), 'by price, in stock first');
        $this->assertSame(['1', '2', '3'], $ids('טבעת יהלום הכי יקרה'));
        $this->assertSame(['2'], $ids('טבעת יהלום במבצע'));
        $this->assertSame(['2'], $ids('טבעת יהלום גדולה מ-20'));
        $this->assertSame(['3'], $ids('טבעת יהלום קטנה מ-17'));
        $this->assertSame(['2', '1', '3'], $ids('טבעת יהלום גדולה'), 'the biggest first');
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4'], $ids('טבעת או עגילי'));
    }

    public function test_the_browser_reads_and_applies_the_phrases_as_the_server_does(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }
        $registry = BuildSearchIndex::registry();
        $records = [
            ['id' => 'p:1', 'pr' => 900, 's' => 1, 'z' => 18, 'd' => 20260101], ['id' => 'p:2', 'pr' => 500, 's' => 1, 'sl' => 1, 'z' => 22, 'd' => 20260201],
            ['id' => 'p:3', 'pr' => 300, 's' => 0, 'z' => 16], ['id' => 'p:4', 'pr' => 700, 's' => 1, 'sl' => 1], ['id' => 'p:5', 's' => 1, 'z' => 20],
        ];
        $queries = ['טבעת הכי זולה', 'טבעת יהלום הכי יקרה במבצע', 'עגילים גדולים מ-2,5', 'טבעת שווה ל 20', 'הכי חדש', 'טבעת קטנה', 'או טבעת או עגילים במלאי', 'טבעת גדולה במלאי', 'cheapest ring on sale',
            'טבעת רובי במידה גדולה', 'טבעת במידות קטנות', 'ring in large sizes'];

        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const reg='.json_encode($registry, JSON_UNESCAPED_UNICODE).';const recs='.json_encode($records).';const out={};'
            .'for(const q of '.json_encode($queries, JSON_UNESCAPED_UNICODE).'){const r=e.readActions(q,reg);out[q]={query:r.query,actions:r.actions,either:e.either(r.query),order:e.applyActions(recs,r.actions).map(x=>x.id)};}'
            .'process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);

        foreach ($queries as $query) {
            [$text, $actions] = HebrewSearch::actions($query, $registry);
            $server = ['query' => $text, 'actions' => $actions, 'either' => HebrewSearch::either($text), 'order' => array_column(HebrewSearch::applyActions($records, $actions), 'id')];
            $this->assertEquals($server, $browser[$query], "\"{$query}\" differs between the server and the browser");
        }
        $this->assertSame(['p:4', 'p:2'], $browser['טבעת יהלום הכי יקרה במבצע']['order'], 'on sale only, the dearest first');
        $this->assertSame(['טבעת', 'עגילים'], $browser['או טבעת או עגילים במלאי']['either']);
        // "במידה גדולה": a size asked for, not words to find; the largest third of the sizes found (16 18 20 22 → 22).
        $this->assertSame('טבעת רובי', $browser['טבעת רובי במידה גדולה']['query']);
        $this->assertSame(['p:2'], $browser['טבעת רובי במידה גדולה']['order']);
        $this->assertSame(['p:3'], $browser['טבעת במידות קטנות']['order']);
    }

    public function test_a_size_band_reads_the_sizes_there_are_and_is_said_to_the_drawer_without_them(): void
    {
        [$text, $actions] = HebrewSearch::actions('טבעת רובי במידה גדולה', BuildSearchIndex::registry());

        $this->assertSame('טבעת רובי', $text, 'in a shop with no size attribute too: the words are not searched for');
        $this->assertSame('top', $actions['band']);
        $this->assertSame(['large_sizes'], $actions['ids'], 'the drawer reads it, to choose variant sizes or to say there are none');
        $this->assertSame([['id' => 'p:1']], HebrewSearch::applyActions([['id' => 'p:1']], $actions), 'with no size on any product, nothing is dropped');
        $this->assertSame(22.0, HebrewSearch::band([16, 18, 20, 22], 'top'));
        $this->assertSame(16.0, HebrewSearch::band([16, 18, 20, 22], 'bottom'));
    }

    public function test_words_that_name_a_filter_value_are_read_as_that_filter_in_the_browser_as_on_the_server(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'rug', 'l' => 'שטיח']]],
            ['k' => 'size', 'l' => 'מידה', 'v' => [['k' => 'a', 'l' => '80×150'], ['k' => 'b', 'l' => '120×170'], ['k' => 's', 'l' => 'S']]],
            ['k' => 'color', 'l' => 'צבע', 'v' => [['k' => 'blue', 'l' => 'כחול'], ['k' => 'cream', 'l' => 'קרם']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'g', 'l' => 'זהב'], ['k' => 'yg', 'l' => 'זהב צהוב']]],
        ];
        $queries = ['שטיח עד 1000 שח 80 על 150', 'שטיח כחולה 80X150', 'שטיח בכחול', 'טבעת זהב צהוב', 'שטיח', 'שטיח S', 'שטיח 120×170 קרם'];
        $server = [];
        foreach ($queries as $query) {
            $named = HebrewSearch::facets($query, $facets);
            $server[$query] = ['named' => array_map(fn (array $n): array => [$n['code'], $n['said']], $named), 'left' => $named === [] ? null : HebrewSearch::withoutSaid($query, $named[0]['said'])];
        }

        $this->assertSame([[1000, '80x150']], $server['שטיח עד 1000 שח 80 על 150']['named'], 'a size however it is written');
        $this->assertSame('שטיח עד 1000 שח', $server['שטיח עד 1000 שח 80 על 150']['left'], 'the × takes out exactly those words');
        $this->assertSame([[1000, '80x150'], [2000, 'כחולה']], $server['שטיח כחולה 80X150']['named'], 'a colour in the feminine');
        $this->assertSame('שטיח', $server['שטיח בכחול']['left'], 'with a letter in front');
        $this->assertSame([[3001, 'זהב צהוב']], $server['טבעת זהב צהוב']['named'], 'the longest name, not also "זהב"');
        $this->assertSame([], $server['שטיח']['named'], 'never the type');
        $this->assertSame([], $server['שטיח S']['named'], 'too short to be meant');

        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const f='.json_encode($facets, JSON_UNESCAPED_UNICODE).';const out={};'
            .'for(const q of '.json_encode($queries, JSON_UNESCAPED_UNICODE).'){const n=e.queryFacets(q,f);out[q]={named:n.map(x=>[x.code,x.said]),left:n.length?e.withoutSaid(q,n[0].said):null};}'
            .'process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();

        $this->assertEquals($server, json_decode($process->getOutput(), true), 'the browser reads the words as the server does');
    }

    public function test_the_server_searches_a_filter_word_as_a_filter_only_when_what_it_finds_has_it(): void
    {
        $this->build();
        $index = $this->inShop(fn () => LoadedIndex::for($this->shop->id));

        $this->assertSame('טבעת יהלום', HebrewSearch::withoutFacetWords($index, 'טבעת יהלום זהב לבן'), 'rings marked white gold exist: the metal is a filter, not a word');
        $this->assertSame('עגילי זהב צהוב', HebrewSearch::withoutFacetWords($index, 'עגילי זהב צהוב'), 'no earrings are marked yellow gold: the words stay words');
        $this->assertSame('טבעת יהלום', HebrewSearch::withoutFacetWords($index, 'טבעת יהלום'));
    }

    /** @return array<string, mixed> the index as built and stored */
    private function build(): array
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        return json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
