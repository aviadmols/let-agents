<?php

namespace App\Modules\Tenancy\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Tenancy\Actions\EndTrial;
use App\Modules\Tenancy\Actions\StartTrial;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's plan holds its features and settings from above: a feature the plan leaves out is off
 * whatever the shop chose, a number is at most the plan's, and nothing is turned on or raised. The
 * shop's own choices come back as they were when it moves up. A shop with no plan is held by
 * nothing but its settings.
 */
final class PlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plans_are_the_ones_the_pricing_page_sells(): void
    {
        $offered = Plan::query()->offered()->get();

        $this->assertSame(['essentials', 'starter', 'growth', 'pro'], $offered->pluck('code')->all());
        $this->assertSame([49.0, 99.0, 199.0, 299.0], $offered->map->price()->all());
        $this->assertNull(Plan::byCode('enterprise')?->price(), 'Enterprise is by agreement, never offered');
        $this->assertSame([300, 1000, 3000, 5000], $offered->map->ceiling('assistant.answers_per_month')->all());
        $this->assertSame([0, 150, 500, 1000], $offered->map->ceiling('search.photos_per_month')->all());
        $this->assertSame([500, 2000, 10000, 25000], $offered->map->ceiling('catalog.max_products')->all());
        $this->assertSame([1, 2, 5, 10], $offered->map->ceiling('admin.max_team_members')->all());
        $this->assertSame(['Lets Essentials', 'Lets Pro'], [$offered->first()->billingName(), $offered->last()->billingName()]);
    }

    public function test_a_plan_holds_features_off_and_numbers_down_never_up(): void
    {
        $shop = Shop::factory()->create();
        Features::override('search.photos', true, $shop->id);
        Features::override('widget.whatsapp', true, $shop->id);
        Settings::set('catalog.max_products', 8000, $shop->id);
        Settings::set('search.theme', 'store', $shop->id);

        $shop->forceFill(['plan' => 'essentials'])->save();

        $this->assertFalse(Features::enabled('search.photos', $shop->id));
        $this->assertSame(ValueSource::Plan, Features::source('search.photos', $shop->id));
        $this->assertFalse(Features::enabled('widget.whatsapp', $shop->id));
        $this->assertSame(500, Settings::get('catalog.max_products', $shop->id));
        $this->assertSame(ValueSource::Plan, Settings::source('catalog.max_products', $shop->id));
        $this->assertSame('lets', Settings::get('search.theme', $shop->id), 'the store\'s look is a Growth feature');
        $this->assertSame(300, Settings::get('assistant.answers_per_month', $shop->id));

        // below the ceiling, the shop's own number stands; a feature the shop has off stays off on any plan
        Settings::set('catalog.max_products', 200, $shop->id);
        $this->assertSame(200, Settings::get('catalog.max_products', $shop->id));
        $this->assertSame(ValueSource::Shop, Settings::source('catalog.max_products', $shop->id));

        // up to Pro: everything the shop chose is back, and still nothing it did not choose is on
        $shop->forceFill(['plan' => 'pro'])->save();
        Settings::set('catalog.max_products', 8000, $shop->id);

        $this->assertTrue(Features::enabled('search.photos', $shop->id));
        $this->assertTrue(Features::enabled('widget.whatsapp', $shop->id));
        $this->assertSame(8000, Settings::get('catalog.max_products', $shop->id));
        $this->assertSame('store', Settings::get('search.theme', $shop->id));
        $this->assertSame(5000, Settings::get('assistant.answers_per_month', $shop->id));
        $this->assertFalse(Features::enabled('shortlist.compare', $shop->id), 'a plan never turns on what is off');
    }

    public function test_a_shop_with_no_plan_and_other_shops_are_held_by_nothing(): void
    {
        $free = Shop::factory()->create();
        $held = Shop::factory()->create(['plan' => 'essentials']);
        Features::override('search.photos', true);

        $this->assertTrue(Features::enabled('search.photos', $free->id));
        $this->assertTrue(Features::enabled('search.photos'), 'the system as a whole has no plan');
        $this->assertFalse(Features::enabled('search.photos', $held->id));
        $this->assertSame(1000000, Settings::get('assistant.answers_per_month', $free->id));
    }

    public function test_a_trial_and_a_plan_together_hold_at_the_lower_of_the_two(): void
    {
        $shop = Shop::factory()->create(['plan' => 'essentials']);

        app(StartTrial::class)->handle($shop);
        $this->assertSame(500, Settings::get('catalog.max_products', $shop->id), 'the plan is below the trial\'s 2,000');

        $shop->forceFill(['plan' => 'growth'])->save();
        $this->assertSame(2000, Settings::get('catalog.max_products', $shop->id), 'the trial is below Growth\'s 10,000');

        app(EndTrial::class)->handle($shop);
        $this->assertSame(10000, Settings::get('catalog.max_products', $shop->id));
    }

    public function test_a_plan_changed_by_the_operator_holds_at_once(): void
    {
        $shop = Shop::factory()->create(['plan' => 'pro']);
        $this->assertTrue(Features::enabled('assistant.ask_review', $shop->id));

        $plan = Plan::byCode('pro');
        $plan->limits = ['features' => ['assistant.ask_review' => false], 'settings' => []];
        $plan->save();

        $this->assertFalse(Features::enabled('assistant.ask_review', $shop->id));
    }

    public function test_the_month_counts_each_shop_and_meter_and_stops_at_its_allowance(): void
    {
        $usage = app(MonthlyUsage::class);

        $this->assertTrue($usage->take('shop-a', 'answers', 2));
        $this->assertTrue($usage->take('shop-a', 'answers', 2));
        $this->assertFalse($usage->take('shop-a', 'answers', 2));
        $this->assertTrue($usage->take('shop-a', 'photos', 2), 'another meter counts on its own');
        $this->assertTrue($usage->take('shop-b', 'answers', 2), 'another shop counts on its own');
        $this->assertFalse($usage->take('shop-b', 'photos', 0), 'an allowance of none takes none');
        $this->assertSame(2, $usage->used('shop-a', 'answers'));

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $this->assertSame(0, $usage->used('shop-a', 'answers'));
        $this->assertTrue($usage->take('shop-a', 'answers', 2), 'a new month starts again');
    }

    public function test_a_plan_says_what_it_gives_in_the_merchants_words(): void
    {
        app()->setLocale('en');

        $essentials = Plan::byCode('essentials')->lines();
        $pro = Plan::byCode('pro')->lines();

        $this->assertContains('300 AI answers a month', $essentials);
        $this->assertContains('No photo search', $essentials);
        $this->assertContains('One team member', $essentials);
        $this->assertNotContains('WhatsApp handoff when there is no answer', $essentials);
        $this->assertContains('1,000 photo searches a month', $pro);
        $this->assertContains('Insights for the last 24 months', $pro);
        $this->assertContains('WhatsApp handoff when there is no answer', $pro);
        $this->assertContains('Unlimited knowledge pages', $pro);
    }
}
