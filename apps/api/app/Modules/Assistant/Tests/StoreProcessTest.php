<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Benchmark\BenchReports;
use App\Modules\Assistant\Benchmark\WriterBench;
use App\Modules\Assistant\Models\AssistantBenchReport;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each shop's process on its own page: what was read, each agent's last run, the benchmarks'
 * reports with each question's result, and the log. One shop never sees another's.
 */
final class StoreProcessTest extends TestCase
{
    use RefreshDatabase;

    private Shop $gold;

    private Shop $tools;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $this->gold = Shop::factory()->create(['name' => 'Gold']);
        $this->tools = Shop::factory()->create(['name' => 'Tools']);
    }

    public function test_the_benchmark_is_kept_per_shop_with_each_question(): void
    {
        $shops = app(BenchReports::class)->factSheet('v5', [
            $this->judged('a', $this->gold->id, 'יש טבעות בזהב?'),
            $this->judged('b', $this->tools->id, 'איזו מקדחה הכי חזקה?'),
            $this->judged('c', $this->tools->id, 'מה מדיניות הביטולים?'),
        ]);
        app(BenchReports::class)->factSheet('v5', [$this->judged('b', $this->tools->id, 'איזו מקדחה הכי חזקה?')]);

        $this->assertSame(2, $shops, 'one report for each shop');
        $reports = app(TenantContext::class)->runUnscoped(fn () => AssistantBenchReport::query()->orderBy('questions')->get());
        $this->assertCount(2, $reports, 'the same run is written over, never twice');
        $this->assertSame([[$this->gold->id, 1], [$this->tools->id, 1]], $reports->map(fn ($r) => [$r->shop_id, $r->questions])->sortBy(0)->values()->all());
        $row = $reports->firstWhere('shop_id', $this->gold->id)->rows[0];
        $this->assertSame('יש טבעות בזהב?', $row['question']);
        $this->assertTrue($row['writers'][WriterBench::CURRENT]['correct']);
        $this->assertSame(['טבעת זהב'], $row['writers'][WriterBench::CURRENT]['picks'], 'picks by their names');
    }

    public function test_the_page_shows_the_chosen_shop_only(): void
    {
        app(BenchReports::class)->factSheet('v5', [$this->judged('a', $this->gold->id, 'יש טבעות בזהב?'), $this->judged('b', $this->tools->id, 'איזו מקדחה הכי חזקה?')]);
        app(TenantContext::class)->run($this->gold->id, fn () => CatalogProduct::query()->create(['shop_id' => $this->gold->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish',
            'title' => 'טבעת זהב', 'url' => 'https://gold.test/1', 'in_stock' => true, 'purchasable' => true, 'hash' => 'h', 'payload' => [], 'price' => 900]));
        $ours = app(RecordsRuns::class)->track('enrichment.code_reader', 'enrichment.read_in_code', fn (RunContext $run) => $run->output(['n' => 1]), $this->gold->id);
        $theirs = app(RecordsRuns::class)->track('enrichment.code_reader', 'enrichment.read_in_code', fn (RunContext $run) => $run->output(['n' => 2]), $this->tools->id);
        $report = app(TenantContext::class)->runUnscoped(fn () => AssistantBenchReport::query()->where('shop_id', $this->gold->id)->sole());

        $page = $this->withSession(['operator.shop' => $this->gold->id])->get('/operator/store-process?report='.$report->id)->assertOk();

        $page->assertSee('Store process')->assertSee('יש טבעות בזהב?')->assertDontSee('איזו מקדחה הכי חזקה?');
        $page->assertSee('1 in all, 1 active, 1 in stock');
        $page->assertSee('/operator/runs/'.$ours->id, false)->assertDontSee($theirs->id, false);
    }

    public function test_without_a_chosen_shop_the_page_is_closed(): void
    {
        $this->withSession(['operator.shop' => 'all'])->get('/operator/store-process')->assertForbidden();
    }

    /** @return array<string, mixed> a judged writer-benchmark record, the current writer right */
    private function judged(string $id, string $shopId, string $question): array
    {
        return [
            'id' => $id, 'shop_id' => $shopId, 'question' => $question, 'language' => 'he',
            'context' => ['productsByRef' => ['p1' => ['external_id' => '1', 'title' => 'טבעת זהב']]],
            'writers' => [WriterBench::CURRENT => ['answer' => 'כן, יש.', 'picks' => ['1'], 'check' => ['verdict' => 'kept', 'cost_usd' => 0], 'latency_ms' => 900, 'cost_usd' => 0.001,
                'code' => ['internal_ids' => [], 'foreign_script' => [], 'language_ok' => true, 'invalid_picks' => 0, 'out_of_stock_picks' => 0, 'outside_price' => 0, 'other_brand' => 0, 'price_order' => true]]],
            'judge' => ['answerable' => true, 'answerable_note' => 'gold ring', 'best' => WriterBench::CURRENT, 'cost_usd' => 0.01,
                'verdicts' => [WriterBench::CURRENT => ['answer_correct' => true, 'picks_correct' => true, 'hallucination' => false, 'wrong_attribution' => false, 'language_correct' => true]]],
        ];
    }
}
