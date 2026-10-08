<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Modules\Assistant\Support\DailyQuestions;
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
}
