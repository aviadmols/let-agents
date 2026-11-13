<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A store installed again while its deletion waited (Shopify review, 13.11): nothing was deleted, and Today says so,
 * with the way to start from scratch, until the owner closes it.
 */
final class WelcomeBackTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_tells_the_owner_the_data_came_back_until_they_close_it(): void
    {
        $shop = Shop::factory()->create(['name' => 'רוקס', 'content_locale' => 'he']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $owner->attachShop($shop, ShopRole::Owner);
        $this->actingAs($owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        Livewire::test(ShopOverview::class)->assertDontSee('ברוכים השבים');

        Settings::set('admin.deletion_called_off', '2026-11-13', (string) $shop->id);
        Livewire::test(ShopOverview::class)
            ->assertSee('ברוכים השבים: המידע שלכם כאן')
            ->assertSee('ב־13.11.2026 ביקשתם למחוק את החנות')
            ->assertSee('לעמוד הפרטיות')
            ->call('closeCalledOff')
            ->assertDontSee('ברוכים השבים');

        $this->assertSame('', (string) Settings::get('admin.deletion_called_off', (string) $shop->id));
    }
}
