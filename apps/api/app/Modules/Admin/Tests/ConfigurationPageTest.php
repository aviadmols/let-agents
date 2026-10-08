<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Operator\Pages\Configuration;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings are read and written one area at a time, the way a shop's own settings usually are.
 * Only the area on the screen is filled and only it is saved, and nothing on it says "inherits":
 * a switch stands where it stands, and an empty box shows what it would use.
 */
final class ConfigurationPageTest extends TestCase
{
    use RefreshDatabase;

    /** Where the platform's own keys live, now that a store's keys have areas of their own. */
    private const TENANCY = 'module:tenancy';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_the_page_edits_the_shop_the_panel_is_inside(): void
    {
        $shop = Shop::factory()->create();

        // Across every shop, these are the defaults every shop inherits.
        Livewire::test(Configuration::class)->assertSet('shop', null);

        $this->inShop($shop, fn () => Livewire::test(Configuration::class)->assertSet('shop', $shop->id));

        // The shop is not a value the request may set: naming another one changes nothing.
        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->set('shop', Shop::factory()->create()->id)
            ->assertSet('shop', $shop->id));
    }

    public function test_one_area_is_on_the_screen_at_a_time_and_every_key_has_an_area(): void
    {
        $page = Livewire::test(Configuration::class);
        $areas = $page->instance()->areas();

        // A store's own areas come before the modules.
        $this->assertSame(['shown', 'placement', 'panels', 'assistant', 'search', 'whatsapp', 'signup', 'recovery'], $page->instance()->storeAreas());
        $this->assertArrayHasKey(self::TENANCY, $areas);

        // Every declared key belongs to exactly one area.
        $keys = array_merge(...array_column($areas, 'keys'));
        $this->assertSame(array_values(array_unique($keys)), $keys, 'no key is offered twice');
        $this->assertContains('tenancy.api_access', $keys);
        $this->assertContains('widget.layout', $keys);

        // And only the open area is on the form.
        $page->call('openArea', self::TENANCY)
            ->assertFormFieldExists('f__tenancy__api_access')
            ->assertFormFieldExists('s__tenancy__max_active_api_keys')
            ->assertFormFieldDoesNotExist('s__widget__layout');

        $page->call('openArea', 'shown')
            ->assertFormFieldExists('s__widget__layout')
            ->assertFormFieldDoesNotExist('f__tenancy__api_access');
    }

    public function test_a_switch_moved_away_from_the_platforms_answer_is_kept_and_moved_back_is_cleared(): void
    {
        $shop = Shop::factory()->create();
        $this->assertTrue(Features::enabled('tenancy.api_access'), 'on by default');

        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->assertSet('data.f__tenancy__api_access', true)
            ->set('data.f__tenancy__api_access', false)
            ->call('save')
            ->assertHasNoFormErrors());

        $this->assertFalse(Features::enabled('tenancy.api_access', $shop->id));
        $this->assertTrue(Features::enabled('tenancy.api_access'), 'and only for that shop');

        // Back where it was is not a decision, so the shop stops holding one of its own.
        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->set('data.f__tenancy__api_access', true)
            ->call('save'));

        $this->assertSame(ValueSource::Default, Features::source('tenancy.api_access', $shop->id));
    }

    public function test_global_values_are_saved_for_the_whole_system(): void
    {
        Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->fillForm(['s__tenancy__max_active_api_keys' => '5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, Settings::get('tenancy.max_active_api_keys'));
    }

    public function test_shop_values_override_only_that_shop_and_hide_global_only_settings(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();

        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', 'module:admin')
            ->assertFormFieldDoesNotExist('s__admin__default_locale'));

        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->fillForm(['s__tenancy__api_requests_per_minute' => '120'])
            ->call('save')
            ->assertHasNoFormErrors());

        $this->assertSame(120, Settings::get('tenancy.api_requests_per_minute', $shop->id));
        $this->assertSame(ValueSource::Shop, Settings::source('tenancy.api_requests_per_minute', $shop->id));
        $this->assertSame(600, Settings::get('tenancy.api_requests_per_minute', $other->id));
    }

    public function test_an_out_of_range_value_saves_nothing(): void
    {
        Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->fillForm([
                's__tenancy__max_active_api_keys' => '500',
                's__tenancy__api_requests_per_minute' => '120',
            ])
            ->call('save')
            ->assertHasErrors(['data.s__tenancy__max_active_api_keys']);

        $this->assertDatabaseCount('setting_overrides', 0);
    }

    public function test_an_empty_box_returns_a_value_to_the_platforms(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('tenancy.max_active_api_keys', 7, $shop->id);

        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', self::TENANCY)
            ->assertSet('data.s__tenancy__max_active_api_keys', '7')
            ->fillForm(['s__tenancy__max_active_api_keys' => null])
            ->call('save')
            ->assertHasNoFormErrors());

        $this->assertSame(ValueSource::Default, Settings::source('tenancy.max_active_api_keys', $shop->id));
    }

    public function test_saving_one_area_leaves_the_others_alone(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('tenancy.max_active_api_keys', 7, $shop->id);

        // A save from another area must not clear a value it never showed.
        $this->inShop($shop, fn () => Livewire::test(Configuration::class)
            ->call('openArea', 'shown')
            ->fillForm(['s__widget__max_products' => '6'])
            ->call('save')
            ->assertHasNoFormErrors());

        $this->assertSame(6, Settings::get('widget.max_products', $shop->id));
        $this->assertSame(7, Settings::get('tenancy.max_active_api_keys', $shop->id), 'untouched by a save it was not part of');
    }

    public function test_the_page_renders_in_both_languages(): void
    {
        $shop = Shop::factory()->create();
        $this->post('/admin/shop', ['shop' => $shop->id]);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get('/operator/configuration?area='.self::TENANCY)
                ->assertOk()
                ->assertSee(__('tenancy::settings.max_active_api_keys.label', [], $locale));
        }
    }

    private function inShop(Shop $shop, callable $do): mixed
    {
        return app(TenantContext::class)->run($shop->id, $do);
    }
}
