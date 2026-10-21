<?php

namespace App\Modules\Admin\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\GeneralSettings;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Filament\Merchant\Pages\UsersSettings;
use App\Modules\Admin\Mail\ShopInvitationMail;
use App\Modules\Admin\Models\ShopInvitation;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · Users: who signs in to the shop's panel, and real invitations. An owner invites someone as
 * an owner, the team or a viewer; the email carries a signed link where they set their own password and
 * come straight in. Only owners invite and remove; a viewer changes nothing anywhere.
 */
final class UsersSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold', 'domain' => 'rocks.example.com']);
        $this->owner = User::factory()->create(['name' => 'אביעד', 'last_login_at' => now()]);
        $this->owner->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($this->owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_the_page_lists_the_people_with_their_roles_and_the_form(): void
    {
        $member = User::factory()->create(['name' => 'שירה', 'last_login_at' => now()->subDay()]);
        $member->attachShop($this->shop, ShopRole::Member);

        Livewire::test(UsersSettings::class)
            ->assertSee('מי נכנס לחשבון')
            ->assertSee('2 משתמשים')
            ->assertSeeInOrder(['אביעד', 'בעלים · נכנס היום', 'שירה', 'צוות · עונה לשאלות ופניות, עורך ידע ותגיות · נכנס אתמול', 'להסיר'])
            ->assertSee('לפתוח משתמש')
            ->assertSee('name@rocks.example.com')
            ->assertSee('הכל, כולל מנוי ומשתמשים')
            ->assertSee('רואה אנליטיקס ודוחות בלבד')
            ->assertSee('לשלוח הזמנה')
            ->assertSee('המשתמש מקבל מייל עם קישור כניסה וקובע סיסמה בעצמו');
    }

    public function test_an_owner_invites_and_the_link_sets_a_password_and_lets_them_in(): void
    {
        Mail::fake();

        Livewire::test(UsersSettings::class)
            ->set('data.name', 'דן')
            ->set('data.email', 'Dan@Agency.co.il')
            ->set('data.role', 'viewer')
            ->set('data.locale', 'en')
            ->call('invite')
            ->assertNotified('ההזמנה נשלחה במייל')
            ->assertSee('dan@agency.co.il')
            ->assertSee('ממתין')
            ->assertSee('לשלוח שוב');

        $invitation = ShopInvitation::query()->firstOrFail();
        $this->assertSame(ShopRole::Viewer, $invitation->role);
        $this->assertSame('en', $invitation->locale);
        $this->assertSame($this->owner->id, $invitation->invited_by);

        $link = null;
        Mail::assertSent(ShopInvitationMail::class, function (ShopInvitationMail $mail) use (&$link): bool {
            $link = $mail->link;

            return $mail->hasTo('dan@agency.co.il') && $mail->locale === 'en' && $mail->shopName === 'Rocks and Gold';
        });
        $this->assertStringContainsString('signature=', (string) $link);

        // A stranger opens it: a form to set a name and a password, in the invitation's language.
        Auth::logout();
        $this->get((string) $link)->assertOk()->assertSee('Join Rocks and Gold')->assertSee('dan@agency.co.il');

        // A short password is refused; a good one creates the account and signs them in.
        $this->post((string) $link, ['name' => 'Dan', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post((string) $link, ['name' => 'Dan', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'])
            ->assertRedirect(ShopOverview::getUrl(panel: User::MERCHANT_PANEL, tenant: $this->shop));

        $dan = User::query()->where('email', 'dan@agency.co.il')->firstOrFail();
        $this->assertAuthenticatedAs($dan);
        $this->assertTrue(Hash::check('a-good-password', $dan->password));
        $this->assertSame(ShopRole::Viewer, $dan->roleIn($this->shop));
        $this->assertSame('en', $dan->locale);
        $this->assertNotNull($invitation->fresh()?->accepted_at);

        // The link works once.
        Auth::logout();
        $this->get((string) $link)->assertOk()->assertSee('This invitation was used already');
    }

    public function test_sending_again_makes_a_new_link_and_the_old_one_stops(): void
    {
        Mail::fake();
        Livewire::test(UsersSettings::class)->set('data.email', 'dan@agency.co.il')->call('invite');
        $first = ShopInvitation::query()->firstOrFail();
        $links = [];

        Livewire::test(UsersSettings::class)->call('resend', $first->id)->assertNotified('ההזמנה נשלחה שוב');

        Mail::assertSent(ShopInvitationMail::class, function (ShopInvitationMail $mail) use (&$links): bool {
            $links[] = $mail->link;

            return true;
        });
        $this->assertCount(2, $links);
        $this->assertNotSame($links[0], $links[1]);
        $this->assertSame(1, ShopInvitation::query()->count(), 'one invitation per email');

        Auth::logout();
        $this->get($links[0])->assertOk()->assertSee('הקישור לא נמצא');
        $this->get($links[1])->assertOk()->assertSee('הצטרפות ל-Rocks and Gold');

        // A changed address is not the signed one; an old one has run out.
        $this->get(str_replace('signature=', 'signature=0', $links[1]))->assertOk()->assertSee('הקישור לא נמצא');
        $this->travel(8)->days();
        $this->get($links[1])->assertOk()->assertSee('תוקף ההזמנה פג');
    }

    public function test_someone_with_an_account_joins_without_a_new_password(): void
    {
        Mail::fake();
        $existing = User::factory()->create(['email' => 'shira@example.com', 'password' => 'her-own-password']);
        Livewire::test(UsersSettings::class)->set('data.email', 'shira@example.com')->set('data.role', 'member')->call('invite');

        $link = null;
        Mail::assertSent(ShopInvitationMail::class, function (ShopInvitationMail $mail) use (&$link): bool {
            $link = $mail->link;

            return true;
        });

        Auth::logout();
        $this->get((string) $link)->assertOk()->assertSee('נוספתם ל-Rocks and Gold');
        $this->assertGuest();
        $this->assertSame(ShopRole::Member, $existing->roleIn($this->shop));
        $this->assertTrue(Hash::check('her-own-password', (string) $existing->fresh()?->password));
    }

    public function test_an_invitation_is_refused_for_a_wrong_email_or_someone_already_in(): void
    {
        Mail::fake();
        $member = User::factory()->create(['email' => 'in@example.com']);
        $member->attachShop($this->shop, ShopRole::Member);

        Livewire::test(UsersSettings::class)->set('data.email', 'not-an-email')->call('invite')->assertSee('כתובת המייל לא תקינה.');
        Livewire::test(UsersSettings::class)->set('data.email', 'IN@example.com')->call('invite')->assertSee('כבר נמצא בחשבון.');

        Mail::assertNothingSent();
        $this->assertSame(0, ShopInvitation::query()->count());
    }

    public function test_the_shops_plan_holds_how_many_people_its_panel_has(): void
    {
        Mail::fake();
        $this->shop->forceFill(['plan' => 'starter'])->save();

        Livewire::test(UsersSettings::class)->set('data.email', 'dan@agency.co.il')->call('invite');
        $this->assertSame(1, ShopInvitation::query()->count(), 'the owner and one waiting: Starter\'s two');

        Livewire::test(UsersSettings::class)->set('data.email', 'shira@example.com')->call('invite')
            ->assertSee('בתוכנית שלכם יש מקום ל־2 משתמשים');
        $this->assertSame(1, ShopInvitation::query()->count());

        // the one waiting can still be sent again
        Livewire::test(UsersSettings::class)->set('data.email', 'dan@agency.co.il')->call('invite');
        $this->assertSame(1, ShopInvitation::query()->count());
        Mail::assertSentCount(2);
    }

    public function test_only_owners_invite_and_remove_and_the_last_owner_stays(): void
    {
        Mail::fake();
        $member = User::factory()->create(['name' => 'שירה']);
        $member->attachShop($this->shop, ShopRole::Member);

        // An owner removes a member, never themselves.
        Livewire::test(UsersSettings::class)->call('remove', $this->owner->id);
        $this->assertSame(ShopRole::Owner, $this->owner->roleIn($this->shop));
        Livewire::test(UsersSettings::class)->call('remove', $member->id)->assertNotified('שירה הוסר מהחשבון');
        $this->assertNull($member->roleIn($this->shop));

        // A member does neither.
        $member->attachShop($this->shop, ShopRole::Member);
        $this->actingAs($member);
        Livewire::test(UsersSettings::class)
            ->assertDontSee('לשלוח הזמנה')
            ->assertSee('רק בעלי החשבון יכולים לעשות את זה')
            ->set('data.email', 'x@example.com')
            ->call('invite')
            ->call('remove', $this->owner->id);

        Mail::assertNothingSent();
        $this->assertSame(ShopRole::Owner, $this->owner->roleIn($this->shop));
    }

    public function test_a_viewer_reads_and_changes_nothing(): void
    {
        $viewer = User::factory()->create();
        $viewer->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($viewer);

        $this->assertFalse(Gate::allows('edit-shop', $this->shop));
        $this->assertTrue(Gate::forUser($this->owner)->allows('edit-shop', $this->shop));
        $this->assertFalse(Gate::allows('manage-shop', $this->shop));

        // A settings page opens, and its save does nothing.
        Livewire::test(GeneralSettings::class)
            ->assertOk()
            ->set('data.name', 'Someone else')
            ->call('save')
            ->assertNotified('יש לכם הרשאת צפייה בלבד. אי אפשר לשנות כאן דבר.');

        $this->assertSame('Rocks and Gold', $this->shop->fresh()?->name);

        // The home and the menu still open for them.
        $this->get("/merchant/{$this->shop->slug}/overview")->assertOk();
    }
}
