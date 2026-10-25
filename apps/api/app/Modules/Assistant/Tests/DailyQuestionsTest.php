<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Assistant\Support\DailyQuestions;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A bot that makes up a new shopper id for every question still stops at its address's limit. */
final class DailyQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_shopper_id_every_time_does_not_get_past_the_address_limit(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('assistant.questions_per_address_per_day', 3, $shop->id);

        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            $allowed += DailyQuestions::allow($shop->id, 'visitor-'.$i, '203.0.113.7') ? 1 : 0;
        }

        $this->assertSame(3, $allowed);
        $this->assertTrue(DailyQuestions::allow($shop->id, 'visitor-x', '198.51.100.2'), 'another address still asks');
    }

    public function test_one_shopper_stops_at_their_own_limit_on_any_address(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('assistant.questions_per_visitor_per_day', 2, $shop->id);

        $this->assertTrue(DailyQuestions::allow($shop->id, 'same', '203.0.113.1'));
        $this->assertTrue(DailyQuestions::allow($shop->id, 'same', '203.0.113.2'));
        $this->assertFalse(DailyQuestions::allow($shop->id, 'same', '203.0.113.3'));
    }

    public function test_a_store_that_lifts_the_limit_on_each_shopper_keeps_its_own_daily_cap(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('assistant.questions_per_visitor_per_day', 2, $shop->id);
        Settings::set('assistant.questions_per_address_per_day', 2, $shop->id);
        Settings::set('assistant.questions_per_shop_per_day', 20, $shop->id);
        Features::override('assistant.visitor_limit', false, $shop->id);

        $allowed = 0;
        for ($i = 0; $i < 25; $i++) {
            $allowed += DailyQuestions::allow($shop->id, 'same', '203.0.113.1') ? 1 : 0;
        }

        $this->assertSame(20, $allowed, 'one shopper past their two, up to the store\'s twenty');
    }

    public function test_the_month_holds_the_shop_at_its_plan_and_a_day_refused_takes_none_of_it(): void
    {
        $shop = Shop::factory()->create(['plan' => 'essentials']);
        Features::override('assistant.visitor_limit', false, $shop->id);
        Settings::set('assistant.questions_per_shop_per_day', 10, $shop->id);

        $allowed = 0;
        for ($i = 0; $i < 12; $i++) {
            $allowed += DailyQuestions::allow($shop->id, 'v', '203.0.113.1') ? 1 : 0;
        }
        $this->assertSame(10, $allowed);
        $this->assertSame(10, app(MonthlyUsage::class)->used($shop->id, DailyQuestions::METER), 'the two the day refused took none of the month');

        Settings::set('assistant.answers_per_month', 12, $shop->id);
        $this->travel(1)->days();
        $allowed = 0;
        for ($i = 0; $i < 5; $i++) {
            $allowed += DailyQuestions::allow($shop->id, 'v', '203.0.113.1') ? 1 : 0;
        }
        $this->assertSame(2, $allowed, 'a new day, but only two left of the month');
    }

    public function test_a_demo_store_is_never_counted(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Demo]);
        Settings::set('assistant.questions_per_visitor_per_day', 2, $shop->id);
        Settings::set('assistant.questions_per_address_per_day', 2, $shop->id);
        Settings::set('assistant.answers_per_month', 3, $shop->id);

        $allowed = 0;
        for ($i = 0; $i < 20; $i++) {
            $allowed += DailyQuestions::allow($shop->id, 'same', '203.0.113.1') ? 1 : 0;
        }

        $this->assertSame(20, $allowed);
    }
}
