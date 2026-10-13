<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop manager's home, as in the sketch: what waits today, what is on and what is not, the
 * numbers, what each part brought and the notices; and the notices on a screen of their own. All
 * of it from the modules' home sources, none of it about models or money spent on them.
 */
final class MerchantHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_says_what_is_on_what_is_not_and_what_waits(): void
    {
        $shop = Shop::factory()->create(['name' => 'ספרים ובגדים']);
        $merchant = User::factory()->create(['name' => 'דנה כהן']);
        $merchant->attachShop($shop);
        Features::override('shortlist.wishlist', false, $shop->id);
        Features::override('search.photos', true, $shop->id);

        $html = $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee(', דנה')
            ->assertSee('מה מחכה לכם היום')
            ->assertSee('רשימת המשאלות כבויה')
            ->assertSee('מה מופעל בחנות')
            ->assertSee('data-status="photos" data-state="on"', false)
            ->assertSee('data-status="wishlist" data-state="off"', false)
            ->assertSee('data-status="search" data-state="setup"', false)
            ->assertSee('מה כל חלק הביא')
            ->assertSee('הודעות ותובנות')
            ->getContent();

        preg_match('/<div class="la-page" data-home>.*<\/x-filament-panels::page>|<div class="la-page" data-home>.*/s', $html, $page);
        $this->assertStringNotContainsString('$', strip_tags($page[0]), 'no dollar on the shop manager\'s home');

        $this->get("/merchant/{$shop->slug}/notices")
            ->assertOk()
            ->assertSee('דורש פעולה')
            ->assertSee('עוד לא הוגדר')
            ->assertSee('להפעיל');

        $this->get("/merchant/{$shop->slug}/connection")->assertOk()->assertSee('חיבור החנות');
    }
}
