<?php

namespace App\Modules\Tenancy\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Tenancy\Actions\EndTrial;
use App\Modules\Tenancy\Actions\StartTrial;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop on trial reads up to 2,000 products, answers up to 50 questions a day and reads no pictures;
 * ending the trial (it starts to pay) gives it back the settings it had before.
 */
final class TrialTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_trial_limits_the_shop_and_its_end_gives_back_what_it_had(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('catalog.max_products', 5000, $shop->id);
        Features::override('search.photos', true, $shop->id);

        app(StartTrial::class)->handle($shop);

        $this->assertTrue($shop->refresh()->onTrial());
        $this->assertSame(14, $shop->trialDaysLeft());
        $this->assertSame(2000, Settings::get('catalog.max_products', $shop->id));
        $this->assertSame(50, Settings::get('assistant.questions_per_shop_per_day', $shop->id));
        $this->assertFalse(Features::enabled('search.photos', $shop->id));
        $this->assertFalse(Features::enabled('retrieval.image_index', $shop->id));
        $this->assertFalse(Features::enabled('retrieval.image_captions', $shop->id));

        // Renewed: more days, and what it had before the first trial is still what comes back.
        $this->travel(20)->days();
        $this->assertSame(0, $shop->trialDaysLeft());
        $this->assertTrue($shop->onTrial(), 'past its last day the shop stays limited until the trial is ended');
        app(StartTrial::class)->handle($shop, 7);
        $this->assertSame(7, $shop->refresh()->trialDaysLeft());

        app(EndTrial::class)->handle($shop);

        $this->assertFalse($shop->refresh()->onTrial());
        $this->assertSame(5000, Settings::get('catalog.max_products', $shop->id));
        $this->assertNull(Settings::overrideFor('assistant.questions_per_shop_per_day', $shop->id));
        $this->assertTrue(Features::enabled('search.photos', $shop->id));
        $this->assertNull(Features::overrideFor('retrieval.image_captions', $shop->id));
    }

    public function test_pictures_may_be_read_on_trial(): void
    {
        Features::override('tenancy.trial_pictures', true);
        $shop = Shop::factory()->create();
        Features::override('search.photos', true, $shop->id);

        app(StartTrial::class)->handle($shop);

        $this->assertTrue(Features::enabled('search.photos', $shop->id));
        $this->assertNull(Features::overrideFor('retrieval.image_index', $shop->id));
        $this->assertSame(2000, Settings::get('catalog.max_products', $shop->id));
    }
}
