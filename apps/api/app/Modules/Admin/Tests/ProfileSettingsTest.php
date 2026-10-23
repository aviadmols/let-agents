<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Contracts\PanelHost;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ProfileSettings;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · My profile: name, email, the panel's language, its display mode (kept on the person and
 * honoured by the panel, and by the top bar's switch), and a new password given with the current one. Inside a
 * platform's admin (a PanelHost: the Shopify admin), which signed the person in, neither a password nor a sign-out.
 */
final class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
        $this->me = User::factory()->create(['name' => 'אביעד', 'email' => 'aviad@example.com', 'password' => 'old-password', 'locale' => 'he']);
        $this->me->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($this->me);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_the_profile_saves_name_email_language_and_display_mode(): void
    {
        // Even a viewer changes their own profile.
        Livewire::test(ProfileSettings::class)
            ->assertSee('הפרופיל שלי')
            ->assertSee('מצב תצוגה')
            ->assertSee('כמו המערכת')
            ->assertSee('להחליף סיסמה')
            ->assertSet('data.name', 'אביעד')
            ->assertSet('data.theme', 'system')
            ->set('data.name', 'אביעד מולס')
            ->set('data.email', 'Aviad@Lets.co.il')
            ->set('data.theme', 'dark')
            ->call('save')
            ->assertNotified('נשמר');

        $me = $this->me->fresh();
        $this->assertSame('אביעד מולס', $me?->name);
        $this->assertSame('aviad@lets.co.il', $me?->email);
        $this->assertSame('dark', $me?->theme);
        $this->assertTrue(Hash::check('old-password', (string) $me?->password), 'no password typed, none changed');

        // The panel opens in it on any device: it is set before Filament reads it.
        $this->get("/merchant/{$this->shop->slug}/overview")->assertOk()->assertSee('localStorage.setItem("theme","dark")', false);

        // A new language redraws the panel in it.
        Livewire::test(ProfileSettings::class)->set('data.locale', 'en')->call('save')->assertRedirect(ProfileSettings::getUrl());
        $this->assertSame('en', $this->me->fresh()?->locale);
    }

    public function test_a_new_password_needs_the_current_one(): void
    {
        Livewire::test(ProfileSettings::class)
            ->call('$set', 'changingPassword', true)
            ->assertSee('הסיסמה הנוכחית')
            ->set('data.current', 'wrong')
            ->set('data.password', 'new-password-1')
            ->set('data.password_again', 'new-password-1')
            ->call('save')
            ->assertSee('הסיסמה הנוכחית לא נכונה.')
            ->set('data.current', 'old-password')
            ->set('data.password_again', 'something-else')
            ->call('save')
            ->assertSee('הסיסמאות לא זהות.')
            ->set('data.password_again', 'new-password-1')
            ->call('save')
            ->assertNotified('נשמר')
            ->assertSet('data.password', '');

        $this->assertTrue(Hash::check('new-password-1', (string) $this->me->fresh()?->password));
    }

    public function test_an_email_of_someone_else_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(ProfileSettings::class)->set('data.email', 'taken@example.com')->call('save')->assertSee('המייל הזה כבר שייך למשתמש אחר.');

        $this->assertSame('aviad@example.com', $this->me->fresh()?->email);
    }

    public function test_inside_a_platforms_admin_there_is_no_password_and_no_sign_out(): void
    {
        $this->app->instance('test.panel_host', new class implements PanelHost
        {
            public function hosts(Request $request): bool
            {
                return $request->headers->get('X-Test-Hosted') === '1';
            }
        });
        $this->app->tag(['test.panel_host'], PanelHost::TAG);

        // Outside: both, as ever.
        $this->get("/merchant/{$this->shop->slug}/settings/profile")->assertOk()->assertSee('data-logout', false)->assertSee('data-change-password', false);

        // Inside: the platform signed them in; nothing would lead to an email and password form.
        $this->get("/merchant/{$this->shop->slug}/settings/profile", ['X-Test-Hosted' => '1'])->assertOk()
            ->assertDontSee('data-logout', false)
            ->assertDontSee('data-change-password', false)
            ->assertSee('data-user-menu', false);

        // And a password sent anyway is not changed: the page opened inside.
        Livewire::withHeaders(['X-Test-Hosted' => '1'])->test(ProfileSettings::class)
            ->assertSet('hosted', true)
            ->assertDontSee('להחליף סיסמה')
            ->set('data.current', 'old-password')
            ->set('data.password', 'new-password-1')
            ->set('data.password_again', 'new-password-1')
            ->call('save')
            ->assertNotified('נשמר');
        $this->assertTrue(Hash::check('old-password', (string) $this->me->fresh()?->password));
    }

    public function test_the_top_bar_switch_keeps_the_mode_on_the_person(): void
    {
        $this->post('/admin/theme/light')->assertNoContent();
        $this->assertSame('light', $this->me->fresh()?->theme);

        $this->post('/admin/theme/purple')->assertNotFound();
        $this->assertSame('light', $this->me->fresh()?->theme);
    }
}
