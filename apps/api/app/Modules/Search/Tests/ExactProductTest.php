<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
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
 * A search that is plainly for one product, by its code (SKU) or because only it holds every
 * word, says so: the result names that product, puts it first, and the storefront shows it alone
 * with the rest behind "similar items". The browser picks the same product as the server.
 */
final class ExactProductTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../resources/search/let-agents-search.js';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([
                '1' => ['טבעת סוליטר יהלום כסף 925', 'RG-1001', 900],
                '2' => ['טבעת סוליטר יהלום זהב 14 קראט', 'RG-1002', 2400],
                '3' => ['טבעת כסף סוליטר זירקון', 'RG-1003', 300],
                '4' => ['שרשרת כסף עם תליון יהלום', 'NK-77', 1200],
                '5' => ['עגילי כסף', 'NK-77', 200],
                '6' => ['צמיד זהב', null, 1500],
            ] as $id => [$title, $sku, $price]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'sku' => $sku, 'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true,
                    'hash' => "h{$id}", 'payload' => [], 'price' => $price,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_product_code_is_that_product_alone_however_it_is_typed(): void
    {
        foreach (['RG-1002', 'rg1002', 'rg 1002', 'RG–1002'] as $typed) {
            $result = $this->search($typed);

            $this->assertSame(['id' => 'p:2', 'by' => 'sku'], $result['single'], "\"{$typed}\"");
            $this->assertSame('2', $result['groups']['product'][0]['external_id'], "\"{$typed}\": the product first");
            $this->assertSame('RG-1002', $result['groups']['product'][0]['sku'], 'its code, as the store writes it, to show');
        }
    }

    public function test_the_only_product_that_holds_every_word_is_the_one(): void
    {
        $result = $this->search('טבעת יהלום סוליטר בכסף');

        $this->assertSame(['id' => 'p:1', 'by' => 'words'], $result['single']);
        $this->assertSame('1', $result['groups']['product'][0]['external_id']);
    }

    public function test_several_matches_a_shared_code_a_question_or_a_price_are_not_one_product(): void
    {
        $this->assertNull($this->search('טבעת סוליטר')['single'], 'three solitaires');
        $this->assertNull($this->search('NK-77')['single'], 'two products share the code');
        $this->assertNull($this->search('איזו טבעת סוליטר יהלום בכסף?')['single'], 'a question goes to the assistant');
        $this->assertNull($this->search('טבעת סוליטר יהלום כסף עד 1000')['single'], 'a price range is a list');
        $this->assertNull($this->search('טבעת סוליטר בלי זירקון')['single'], 'without a word is a list');
        $this->assertNull($this->search('קרש')['single'], 'nothing found');
    }

    public function test_the_index_keeps_each_products_code(): void
    {
        $items = collect(json_decode($this->storedItems(), true)['items']);

        $this->assertSame('RG-1001', $items->firstWhere('id', 'p:1')['sku']);
        $this->assertArrayNotHasKey('sku', $items->firstWhere('id', 'p:6'), 'no code, no field');
    }

    public function test_the_browser_picks_the_same_product_as_the_server(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $queries = ['RG-1002', 'rg1003', 'NK-77', 'טבעת יהלום סוליטר בכסף', 'טבעת סוליטר', 'צמיד', 'שרשרת תליון', 'קרש'];
        $file = tempnam(sys_get_temp_dir(), 'la-index');
        file_put_contents($file, $this->storedItems());

        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');'
            .'const items=JSON.parse(require("fs").readFileSync('.json_encode($file).',"utf8")).items;'
            .'const i=e.build(items.map(x=>({id:x.id,title:x.title,keywords:x.kw||""})));const c=e.codes(items);const out={};'
            .'for(const q of '.json_encode($queries, JSON_UNESCAPED_UNICODE).'){out[q]=e.single(c,q,e.search(i,q));}'
            .'process.stdout.write(JSON.stringify(out));';

        try {
            $process = new Process([$node, '-e', $harness]);
            $process->mustRun();
            $browser = json_decode($process->getOutput(), true);
        } finally {
            @unlink($file);
        }

        $index = app(TenantContext::class)->run($this->shop->id, fn (): array => LoadedIndex::for($this->shop->id));

        foreach ($queries as $query) {
            $server = HebrewSearch::single($index['codes'], $query, HebrewSearch::search($index['engine'], $query));
            $this->assertSame($server, $browser[$query], "\"{$query}\" differs between the server and the browser");
        }
        $this->assertSame(['id' => 'p:2', 'by' => 'sku'], $browser['RG-1002']);
    }

    private function storedItems(): string
    {
        return (string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->where('shop_id', $this->shop->id)->value('items'));
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        return app(SearchCatalog::class)->handle($this->shop->id, $query, count: false);
    }
}
