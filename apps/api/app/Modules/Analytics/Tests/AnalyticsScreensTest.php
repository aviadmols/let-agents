<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Filament\Widgets\ReportChart;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The analytics screens: a row of totals, then charts drawn from the same report the plugin
 * shows. The operator also sees model spend per day; the shop owner never sees a cost, a model
 * name or a token count.
 */
final class AnalyticsScreensTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $n = 0;
            foreach (['page_view', 'exposure', 'open', 'click', 'add_to_cart'] as $type) {
                AnalyticsEvent::query()->create([
                    'shop_id' => $this->shop->id, 'event_id' => 'evt'.(++$n), 'type' => $type,
                    'page_type' => 'product', 'page_path' => '/product/jigsaw/', 'product_external_id' => '10',
                    'model' => $type === 'page_view' ? null : 'complement',
                    'source' => $type === 'add_to_cart' ? 'widget' : null,
                    'result' => $type === 'add_to_cart' ? 'added' : null,
                    'visitor_hash' => 'v1', 'session_id' => 's1', 'occurred_at' => now()->subDay(),
                ]);
            }
        });

        Run::query()->create([
            'shop_id' => $this->shop->id, 'agent' => 'enrichment', 'action' => 'read_products',
            'status' => RunStatus::Succeeded, 'trigger' => RunTrigger::Schedule,
            'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_tokens' => 1200, 'output_tokens' => 300,
            'cost_usd' => '0.123400', 'started_at' => now()->subDay(),
        ]);
    }

    public function test_the_operator_sees_totals_charts_and_cost_per_day(): void
    {
        foreach (['he', 'en'] as $locale) {
            $this->actingAs(User::factory()->operator()->create())
                ->withHeader('Accept-Language', $locale)
                ->get('/operator/analytics?shop='.$this->shop->id)
                ->assertOk()
                ->assertSee('data-analytics-stats', false)
                ->assertSee('data-chart="traffic"', false)
                ->assertSee('data-chart="engagement"', false)
                ->assertSee('data-chart="orders"', false)
                ->assertSee('data-chart="sections"', false)
                ->assertSee('data-chart="cost"', false)
                ->assertSee(__('analytics::analytics.charts.cost', [], $locale))
                ->assertSee(__('analytics::analytics.models.complement', [], $locale));
        }
    }

    public function test_the_merchant_sees_the_charts_but_no_cost_model_or_tokens(): void
    {
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);

        foreach (['he', 'en'] as $locale) {
            $this->actingAs($merchant)
                ->withHeader('Accept-Language', $locale)
                ->get("/merchant/{$this->shop->slug}/analytics")
                ->assertOk()
                ->assertSee('data-analytics-stats', false)
                ->assertSee('data-chart="engagement"', false)
                ->assertSee('data-chart="sections"', false)
                ->assertSee(__('analytics::analytics.models.complement', [], $locale))
                ->assertDontSee('data-chart="cost"', false)
                ->assertDontSee(__('analytics::analytics.charts.cost', [], $locale))
                ->assertDontSee(__('analytics::analytics.charts.cost_series', [], $locale))
                ->assertDontSee('gpt-5.4-mini')
                ->assertDontSee('USD')
                ->assertDontSee('0.1234');
        }
    }

    public function test_a_chart_draws_its_series_and_says_so_when_there_is_nothing_yet(): void
    {
        Livewire::test(ReportChart::class, [
            'kind' => 'line', 'title' => 'Opens', 'labels' => ['1.10', '2.10'],
            'series' => [['label' => 'Opens', 'data' => [3, 5]], ['label' => 'Clicks', 'data' => [1, 2]]],
        ])->assertOk()->assertSee('Opens')->assertSee('Clicks')->assertDontSee(__('analytics::analytics.empty'));

        Livewire::test(ReportChart::class, [
            'kind' => 'bar', 'title' => 'Orders', 'labels' => ['1.10'],
            'series' => [['label' => 'Orders', 'data' => [0]]],
        ])->assertOk()->assertSee(__('analytics::analytics.empty'));
    }
}
