<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The results in tiers, in code: of the kind the query asks for and holding every word, of that
 * kind, then the rest as related, or not at all when the first two fill the first screen. As
 * Gueta Avigdor's "פלטת עץ" showed reinforcement strips and casters "עם פלטה" among its boards.
 */
final class SearchTiersTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../resources/search/let-agents-search.js';

    /** @return array<string, array{l: string, h: list<string>}> the kinds as Gueta's vocabulary names them */
    private static function kinds(): array
    {
        $names = [
            't:solid_slab' => 'פלטת עץ מלא (לאמי, בוצ׳ר)',
            't:sheet_panel' => 'פלטה / לביד / OSB / MDF',
            't:caster' => 'גלגל וגלגלת',
            't:angle_bracket' => 'זווית וסרגל חיזוק',
            't:shading_slat' => 'סרגל הצללה',
            't:drill_driver' => 'מברגה/מקדחה',
            't:impact_driver' => 'מברגת אימפקט',
            't:screwdriver' => 'מברג',
            't:wood_paint' => 'צבע עץ',
            't:planed_board' => 'עץ מוקצע (לוח או קורה)',
            't:laminated_beam' => 'קורה דו/תלת שכבתית',
            'c:bits' => 'ביטים ומקדחים',
            'w:מברגה' => 'מברגה',
        ];

        return array_map(fn (string $name): array => ['l' => $name, 'h' => BuildSearchIndex::heads($name)], $names);
    }

    /**
     * Each case: the query, the products found in order as [id, kind, holds every word], the
     * first screen, and the ids and the related start expected.
     *
     * @return array<string, array{string, list<array<int, string|bool>>, int, list<string>, int|null}>
     */
    public static function cases(): array
    {
        return [
            'פלטת עץ: the boards, the caster and the strip as related' => ['פלטת עץ', [
                ['p:1', 't:solid_slab', true], ['p:2', 't:caster', true], ['p:3', 't:sheet_panel', true], ['p:4', 't:angle_bracket', true], ['p:5', 't:solid_slab', false],
            ], 6, ['p:1', 'p:3', 'p:5', 'p:2', 'p:4'], 3],
            'פלטת עץ: enough boards for the first screen, no related at all' => ['פלטת עץ', [
                ['p:1', 't:solid_slab', true], ['p:2', 't:caster', true], ['p:3', 't:sheet_panel', true], ['p:5', 't:solid_slab', false],
            ], 3, ['p:1', 'p:3', 'p:5'], null],
            'גלגל עם פלטה: casters, the one with a plate first; the board related' => ['גלגל עם פלטה', [
                ['p:6', 't:solid_slab', false], ['p:7', 't:caster', false], ['p:8', 't:caster', true],
            ], 6, ['p:8', 'p:7', 'p:6'], 2],
            'סרגל לעץ: strips and shading slats; the board related' => ['סרגל לעץ', [
                ['p:9', 't:solid_slab', false], ['p:10', 't:angle_bracket', true], ['p:11', 't:shading_slat', true],
            ], 6, ['p:10', 'p:11', 'p:9'], 2],
            'סט ביטים: "סט" names no kind; the bits of their category, the drill related' => ['סט ביטים', [
                ['p:12', 't:drill_driver', true], ['p:13', 'c:bits', true],
            ], 6, ['p:13', 'p:12'], 1],
            'מברגה שחורה: drills, the black first; a hand screwdriver is no מברגה' => ['מברגה שחורה', [
                ['p:14', 't:screwdriver', true], ['p:15', 't:impact_driver', false], ['p:16', 't:drill_driver', true],
            ], 6, ['p:16', 'p:15', 'p:14'], 2],
            'a brand before the kind: "מקיטה מברגה" is about the drill' => ['מקיטה מברגה', [
                ['p:17', 't:wood_paint', true], ['p:18', 'w:מברגה', true],
            ], 6, ['p:18', 'p:17'], 1],
            'a query that names no kind found changes nothing' => ['שחור', [
                ['p:14', 't:screwdriver', true], ['p:16', 't:drill_driver', true],
            ], 6, ['p:14', 'p:16'], null],
            'עץ אורן: a laminated beam is pine wood by its own name, the paint is not' => ['עץ אורן', [
                ['p:20', 't:planed_board', true, 'עץ אורן מוקצע 20X45'], ['p:22', 't:wood_paint', true, 'צבע לעץ אורן'], ['p:21', 't:laminated_beam', true, 'עץ אורן דו שכבתי 85X85'],
            ], 6, ['p:20', 'p:21', 'p:22'], 2],
            'a caster is no board by its own name either' => ['פלטת עץ', [
                ['p:2', 't:caster', true, 'גלגל תעשייתי עם פלטה'], ['p:1', 't:solid_slab', true, 'פלטת לאמי אלון'],
            ], 6, ['p:1', 'p:2'], 1],
            'צבע לעץ is paint, not wood' => ['צבע לעץ', [
                ['p:1', 't:solid_slab', true], ['p:19', 't:wood_paint', true],
            ], 6, ['p:19', 'p:1'], 1],
        ];
    }

    /** @param list<array{string, string, bool}> $found */
    #[DataProvider('cases')]
    public function test_the_results_come_in_tiers(string $query, array $found, int $firstScreen, array $ids, ?int $related): void
    {
        [$records, $full] = self::records($found);

        $this->assertSame(['ids' => $ids, 'related' => $related], HebrewSearch::tiers($records, $full, $query, self::kinds(), $firstScreen));
    }

    public function test_the_browser_puts_them_in_the_same_tiers(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $cases = [];
        foreach (self::cases() as $name => [$query, $found, $firstScreen]) {
            [$records, $full] = self::records($found);
            $cases[$name] = ['query' => $query, 'records' => $records, 'full' => (object) $full, 'first' => $firstScreen];
        }

        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const kinds='.json_encode(self::kinds(), JSON_UNESCAPED_UNICODE).';const cases='.json_encode($cases, JSON_UNESCAPED_UNICODE).';const out={};'
            .'for(const [n,c] of Object.entries(cases)){out[n]=e.tiers(c.records,c.full,c.query,kinds,c.first);}process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);

        foreach (self::cases() as $name => [$query, $found, $firstScreen, $ids, $related]) {
            $this->assertSame(['ids' => $ids, 'related' => $related], $browser[$name], "\"{$name}\" differs in the browser");
        }
    }

    public function test_a_product_is_known_by_its_type_then_its_category_and_only_then_by_its_title(): void
    {
        $kinds = [];
        $product = fn (string $title, ?string $brand = null): CatalogProduct => (new CatalogProduct(['title' => $title, 'brand' => $brand]))->setRelation('categories', collect());

        $this->assertSame('t:drill_driver', BuildSearchIndex::kindOf($product('סט ביטים ומברגה'), 'drill_driver', ['drill_driver' => 'מברגה/מקדחה'], $kinds), 'the approved type first, whatever the title says');
        $this->assertSame(['מברגה', 'מקדחה'], $kinds['t:drill_driver']['h']);
        $this->assertSame('w:מברגה', BuildSearchIndex::kindOf($product('Makita מברגה נטענת 18V'), null, [], $kinds), 'a brand before the kind is not the kind');
        $this->assertSame('w:מברגה', BuildSearchIndex::kindOf($product('סט DDF485 מברגה'), null, [], $kinds), '"סט" and a model number are not the kind');
        $this->assertSame('w:מברגה', BuildSearchIndex::kindOf($product('מקיטה מברגה', 'מקיטה'), null, [], $kinds), 'nor a Hebrew brand');
        $this->assertSame(['זווית', 'סרגל'], BuildSearchIndex::heads('זווית וסרגל חיזוק'));
        $this->assertSame(['פלטה', 'לביד', 'osb', 'mdf'], BuildSearchIndex::heads('פלטה / לביד / OSB / MDF'));
    }

    public function test_the_server_returns_the_tiers_and_keeps_what_is_out_of_stock_behind_a_chip(): void
    {
        $shop = Shop::factory()->create();
        LoadedIndex::forget();
        $item = fn (string $id, string $title, string $kw, string $kind, int $stock): array => ['id' => $id, 't' => 'product', 'title' => $title, 'kw' => $kw, 'url' => 'https://shop.test/'.$id, 's' => $stock, 'k' => $kind];
        $items = [
            $item('p:1', 'פלטת לאמי אורן', 'פלטות עץ אורן', 't:solid_slab', 1),
            $item('p:2', 'גלגל גומי לפלטה', 'גלגלים לפלטת עץ פלטה מרובעת', 't:caster', 1),
            $item('p:3', 'פלטת עץ בוצ\'ר אגוז', 'פלטות עץ אגוז', 't:solid_slab', 0),
            $item('p:4', 'סרגל חיזוק לעץ', 'זוויות לחיבור פלטת עץ', 't:angle_bracket', 1),
            $item('p:5', 'פלטת MDF', 'פלטות עץ לביד', 't:sheet_panel', 1),
        ];
        SearchIndex::query()->create(['shop_id' => $shop->id, 'hash' => 'h1', 'counts' => [], 'built_at' => now(),
            'items' => json_encode(['v' => 1, 'items' => $items, 'facets' => [], 'kinds' => self::kinds(), 'actions' => []], JSON_UNESCAPED_UNICODE)]);

        $search = fn (): array => app(SearchCatalog::class)->handle($shop->id, 'פלטת עץ', ['product'], false, 24, false);
        $result = $search();

        $this->assertEqualsCanonicalizing(['p:1', 'p:5'], array_slice($result['ids'], 0, 2), 'the boards in stock first');
        $this->assertEqualsCanonicalizing(['p:2', 'p:4'], array_slice($result['ids'], 2), 'then the caster and the strip, as related');
        $this->assertSame(2, $result['related']);
        $this->assertSame(['p:3'], $result['out_of_stock'], 'the walnut board is out of stock: behind the chip');
        $this->assertSame([null, null, true, true], array_map(fn (array $p): ?bool => $p['related'] ?? null, $result['groups']['product']));

        Settings::set('search.in_stock_only', false, $shop->id);
        $all = $search();
        $this->assertEqualsCanonicalizing(['p:1', 'p:3', 'p:5'], array_slice($all['ids'], 0, 3), 'a shop that shows everything: the walnut board among the boards');
        $this->assertSame([3, []], [$all['related'], $all['out_of_stock']]);
    }

    /**
     * @param  list<array<int, string|bool>>  $found
     * @return array{0: list<array{id: string, k: string}>, 1: array<string, bool>}
     */
    private static function records(array $found): array
    {
        $records = [];
        $full = [];
        foreach ($found as $one) {
            [$id, $kind, $whole] = $one;
            $records[] = ['id' => $id, 'k' => $kind] + (isset($one[3]) ? ['title' => $one[3]] : []);
            if ($whole) {
                $full[$id] = true;
            }
        }

        return [$records, $full];
    }
}
