<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Search\Support\SearchFirstSteps;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lets is live in the store from the first page that loaded it outside the preview: a new store had no suggestion to see
 * or click and one person searching, and stayed "In preview" with its search on (17.11). A page in the preview is not.
 */
final class LiveInStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_that_loaded_lets_outside_the_preview_makes_it_live(): void
    {
        $shop = Shop::factory()->create();
        $live = fn (): bool => collect(app(TenantContext::class)->run($shop->id, fn (): array => app(SearchFirstSteps::class)->steps($shop)))->firstWhere('key', 'on')->done;
        $visit = fn (bool $preview, int $n) => app(TenantContext::class)->run($shop->id, fn () => AnalyticsVisit::query()->create([
            'shop_id' => $shop->id, 'session_id' => 's'.$n, 'visitor_hash' => 'v'.$n, 'visit_number' => 1, 'started_at' => now(), 'last_at' => now(),
            'source' => 'direct', 'device' => 'mobile', 'os' => 'iOS', 'browser' => 'Safari', 'pages' => 1, 'steps' => 1, 'did' => [], 'preview' => $preview, 'ip' => '10.0.0.'.$n,
        ]));

        $this->assertFalse($live(), 'nothing yet');
        $visit(true, 1);
        $this->assertFalse($live(), 'the team in the preview is no shopper');
        $visit(false, 2);
        $this->assertTrue($live(), 'a page of the store that loaded Lets');
    }
}
