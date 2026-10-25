<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Filament\Merchant\Pages\OpeningHours;
use App\Modules\Widget\Filament\Merchant\Pages\WhatsappSettings;
use App\Modules\Widget\Support\ShopWeek;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a shop never set follows where it is: the answering week (Sunday to Thursday in Israel, Monday to
 * Friday elsewhere), the example number, and the WhatsApp words in the shop's own language, also when they
 * were saved as the usual words of another.
 */
final class ShopWeekTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['timezone' => 'America/New_York', 'content_locale' => 'en']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('en');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_a_week_never_set_is_the_usual_one_where_the_shop_is(): void
    {
        $week = ShopWeek::for($this->shop->id);
        $this->assertSame(['', '09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', ''], array_values($week), 'Monday to Friday, Sunday closed');

        Livewire::test(OpeningHours::class)
            ->assertSet('data.days.sunday.open', false)
            ->assertSet('data.days.friday.open', true)
            ->assertSet('data.days.friday.from', '09:00')
            // Saved as it is: nothing becomes the shop's own word.
            ->call('save');

        foreach (['sunday', 'monday', 'friday'] as $day) {
            $this->assertNull(Settings::overrideFor("widget.hours_{$day}", $this->shop->id), "{$day} follows the usual week");
        }

        // A day the shop set is its own, wherever it is.
        Settings::set('widget.hours_sunday', '10:00-14:00', $this->shop->id);
        $this->assertSame('10:00-14:00', ShopWeek::for($this->shop->id)['sunday']);

        // In Israel the declared week stands: Sunday to Thursday.
        $israel = Shop::factory()->create(['timezone' => 'Asia/Jerusalem']);
        $this->assertSame(['09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', '', ''], array_values(ShopWeek::for($israel->id)));
    }

    public function test_the_whatsapp_page_speaks_the_shops_language_and_its_numbers(): void
    {
        // Saved once as the usual Hebrew words (when the store's words were Hebrew): they are the usual ones, in English now.
        Settings::set('widget.whatsapp_title', (string) __('widget::bank.contact.title', [], 'he'), $this->shop->id);

        Livewire::test(WhatsappSettings::class)
            ->assertSet('data.title', (string) __('widget::bank.contact.title', [], 'en'))
            ->assertSee('+1 555 0100')
            ->assertDontSee('050-0000000')
            ->call('save');
        $this->assertNull(Settings::overrideFor('widget.whatsapp_title', $this->shop->id), 'the usual words are not the shop\'s own');

        $this->shop->forceFill(['timezone' => 'Asia/Jerusalem', 'content_locale' => 'he'])->save();
        Filament::setTenant($this->shop->fresh());
        Livewire::test(WhatsappSettings::class)
            ->assertSet('data.title', (string) __('widget::bank.contact.title', [], 'he'))
            ->assertSee('050-0000000');
    }
}
