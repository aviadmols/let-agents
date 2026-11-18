<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A karat is one thing however it is written. Rocks, 18.11: "יש תליונים ב 21 קארט" found pendants set with a stone of
 * "1.21 קראט" and never the two of 21K gold, and the store's AI said there were none.
 */
final class KaratTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function written(): array
    {
        return [
            'קארט' => ['תליונים ב 21 קארט', 'תליונימ ב 21k'],
            'קראט, a hyphen' => ['ב-21 קראט?', 'ב 21k'],
            'plural' => ['טבעת 18 קראטים', 'טבעת 18k'],
            'english' => ['22 Karat gold', '22k gold'],
            'a space before K' => ['זהב 21 K', 'זהב 21k'],
            'as titles write it' => ['זהב 14K', 'זהב 14k'],
            'a weight stays a weight' => ['יהלום 0.21 קראט', 'יהלומ 0 21 קראט'],
            'a weight with a comma' => ['2,21 קרט', '2 21 קרט'],
            'the word alone' => ['קראט', 'קראט'],
        ];
    }

    #[DataProvider('written')]
    public function test_a_karat_reads_the_same_however_it_is_written(string $text, string $read): void
    {
        $this->assertSame($read, HebrewSearch::normalize($text));
    }

    public function test_the_browser_reads_a_karat_as_the_server_does(): void
    {
        $texts = array_column(self::written(), 0);
        $script = 'const e=require('.json_encode(realpath(__DIR__.'/../resources/search/hebrew-search-engine.js')).');process.stdout.write(JSON.stringify('.json_encode($texts).'.map(e.normalize)))';
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not here');
        }
        $process = new Process([$node, '-e', $script]);
        $process->mustRun();

        $this->assertSame(array_map(HebrewSearch::normalize(...), $texts), json_decode($process->getOutput(), true));
    }

    public function test_a_karat_in_words_finds_the_gold_of_that_karat_and_never_a_stone_of_that_fraction(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'תליון מלאך חלול, ללא שרשרת - מק"ט 17142, זהב 21K', '2' => 'תליון סוליטר משובץ בלו טופז במשקל 1.21 קראט, כולל שרשרת - זהב 14K', '3' => 'טבעת סוליטר - זהב 21K'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        $ids = array_column(app(SearchCatalog::class)->handle($shop->id, 'תליונים 21 קארט', ['product'], false, null, false)['groups']['product'], 'external_id');

        $this->assertSame(['1'], $ids);
    }
}
