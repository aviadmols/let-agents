<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The shop sees its site search day by day, and what was searched on a day it picks. */
final class SearchAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shop_sees_searches_per_day_and_a_days_searches(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $tenant = app(TenantContext::class);
        $tenant->run($shop->id, function () use ($shop): void {
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => now()->toDateString(), 'query' => 'טבעת יהלום', 'searches' => 7, 'empty' => 0, 'clicks' => 3, 'last_results' => 12]);
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => now()->subDays(2)->toDateString(), 'query' => 'עגילי פנינה', 'searches' => 2, 'empty' => 2]);
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => now()->toDateString(), 'query' => '[photo]', 'searches' => 4]);
        });
        $tenant->run($other->id, fn () => SearchTerm::query()->create(['shop_id' => $other->id, 'day' => now()->toDateString(), 'query' => 'של חנות אחרת', 'searches' => 50]));

        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $page = $this->actingAs($merchant)->get("/merchant/{$shop->slug}/analytics/search")->assertOk();
        $page->assertSee('טבעת יהלום')->assertSee('עגילי פנינה')->assertDontSee('של חנות אחרת');
        $page->assertSee('data-total="searches"', false)->assertSee('data-day="'.now()->toDateString().'"', false);

        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/analytics/search?day=".now()->toDateString())
            ->assertOk()->assertSee('טבעת יהלום')->assertDontSee('עגילי פנינה');
    }
}
