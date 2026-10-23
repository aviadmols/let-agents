<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Admin\Actions\DeleteOwnAccount;
use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ConnectionSettings;
use App\Modules\Admin\Filament\Merchant\Pages\PrivacySettings;
use App\Modules\Admin\Mail\ShopLeavingMail;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Settings · Privacy and account: the owner downloads everything the shop holds, ends the plan (at once, or at the end
 * of a period a platform was paid for), or asks for the shop to be deleted, with days to keep it; anyone deletes their
 * own account, never leaving a shop with no owner.
 */
final class PrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->shop = Shop::factory()->create(['name' => 'רוקס', 'domain' => 'rocks.test']);
        $this->owner = User::factory()->create(['name' => 'אביעד', 'email' => 'owner@example.com', 'password' => 'owner-password', 'locale' => 'he']);
        $this->owner->attachShop($this->shop, ShopRole::Owner);
        User::factory()->create(['email' => 'team@lets.test', 'is_operator' => true]);
        $this->actingAs($this->owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_the_owner_downloads_everything_the_shop_holds_readable_and_without_secrets(): void
    {
        // a saved list a shopper left, phone encrypted as the module keeps it (written as a row: Admin uses no other module)
        DB::table('shortlist_wishlists')->insert([
            'id' => (string) Str::ulid(), 'shop_id' => $this->shop->id, 'token_hash' => 'secret-hash', 'share_code' => 'rocks0000001', 'items' => '["3"]',
            'phone' => Crypt::encryptString('0501234567'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Settings::set('search.results_per_group', 7, $this->shop->id);
        $viewer = User::factory()->create();
        $viewer->attachShop($this->shop, ShopRole::Viewer);

        $this->actingAs($viewer)->get(route('admin.privacy.export', ['shop' => $this->shop->slug]))->assertForbidden();
        $other = Shop::factory()->create();
        $stranger = User::factory()->create();
        $stranger->attachShop($other, ShopRole::Owner);
        $this->actingAs($stranger)->get(route('admin.privacy.export', ['shop' => $this->shop->slug]))->assertForbidden();

        $response = $this->actingAs($this->owner)->get(route('admin.privacy.export', ['shop' => $this->shop->slug]));
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true);

        $this->assertStringContainsString('רוקס', (string) $zip->getFromName('shop.csv'));
        $this->assertStringContainsString('owner@example.com', (string) $zip->getFromName('team.csv'));
        $this->assertNotFalse($zip->getFromName('README.txt'));
        $this->assertStringContainsString('search.results_per_group', (string) $zip->getFromName('settings.csv'), 'the settings chosen for the shop');

        $lists = (string) $zip->getFromName('Shortlist/shortlist_wishlists.csv');
        $this->assertStringContainsString('0501234567', $lists, 'what a shopper left is read as written, not encrypted');
        $this->assertStringNotContainsString('secret-hash', $lists, 'one-way codes stay out');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $lists, 'Excel reads the Hebrew');
        $this->assertFalse($zip->getFromName('Connections/store_connections.csv'), 'credentials stay out');
        $zip->close();
    }

    public function test_a_shop_no_platform_bills_ends_its_plan_at_once_and_the_team_is_told_to_stop_billing_by_hand(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(PrivacySettings::class)
            ->assertSee('הורדת המידע של החנות')
            ->assertSee('ביטול המנוי')
            ->call('$set', 'confirming', 'plan')
            ->assertSee('לבטל את המנוי עכשיו?')
            ->call('endPlan')
            ->assertNotified('המנוי בוטל. החיפוש ירד מהאתר, והמידע נשמר.');

        $shop = $this->shop->fresh();
        $this->assertSame(ShopStatus::Paused, $shop->status, 'the search leaves the site; nothing is deleted');
        $this->assertNotNull($shop->cancelled_at);
        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::PLAN_ENDED && $mail->billedByHand && $mail->hasTo('team@lets.test'));
    }

    public function test_a_plan_paid_ahead_stops_billing_and_keeps_the_search_until_its_period_ends(): void
    {
        $this->travelTo(now()->startOfSecond());
        $platform = $this->platform(now()->addDays(10));
        $until = now()->addDays(10)->locale('he')->translatedFormat('j.n.Y');

        Livewire::test(PrivacySettings::class)
            ->assertSee('ביטול עוצר את החיוב: לא תחויבו שוב. החיפוש נשאר באתר שלכם עד סוף התקופה שכבר שולמה')
            ->call('$set', 'confirming', 'plan')
            ->assertSee('לבטל את המנוי? החיוב נעצר עכשיו, והחיפוש נשאר באתר שלכם עד סוף התקופה שכבר שולמה.')
            ->call('endPlan')
            ->assertNotified('המנוי בוטל והחיוב נעצר. החיפוש נשאר באתר שלכם עד '.$until.', סוף התקופה שכבר שולמה. אפשר לבחור חבילה מחדש בכל רגע.');

        $shop = $this->shop->fresh();
        $this->assertTrue($platform->cancelled, 'the platform stops billing now');
        $this->assertSame(ShopStatus::Active, $shop->status, 'the search stays on the site');
        $this->assertSame(now()->addDays(10)->toIso8601String(), $shop->plan_ends_at?->toIso8601String());
        $this->assertNotNull($shop->cancelled_at);
        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::PLAN_ENDED && ! $mail->billedByHand && $mail->until !== null);

        Livewire::test(PrivacySettings::class)->assertSee('עד '.$until.', סוף התקופה שכבר שולמה')->assertSee('אפשר לבחור חבילה מחדש בכל רגע');
        Livewire::test(ConnectionSettings::class)->assertSee('בוטל · החיפוש נשאר באתר שלכם עד '.now()->addDays(10)->format('j.n.Y'));

        // In English too.
        $this->owner->forceFill(['locale' => 'en'])->save();
        app()->setLocale('en');
        Livewire::test(PrivacySettings::class)->assertSee('The search stays on your site until '.now()->addDays(10)->locale('en')->translatedFormat('j.n.Y').', the end of the period already paid.');

        // The period over: the shop pauses within the hour, nothing deleted.
        $this->travelTo(now()->addDays(10)->addMinute());
        $this->artisan('shops:end-due-plans')->assertSuccessful();
        $shop = $this->shop->fresh();
        $this->assertSame([ShopStatus::Paused, null], [$shop->status, $shop->plan_ends_at]);
        Livewire::test(PrivacySettings::class)->assertSee('The search is off the site, and all the data is kept.');
    }

    public function test_asking_to_delete_the_shop_takes_the_search_off_at_once_even_with_a_period_paid(): void
    {
        $platform = $this->platform(now()->addDays(10));

        Livewire::test(PrivacySettings::class)->set('shopName', 'רוקס')->call('deleteShop');

        $shop = $this->shop->fresh();
        $this->assertTrue($platform->cancelled);
        $this->assertSame([ShopStatus::Paused, null], [$shop->status, $shop->plan_ends_at], 'the shop asked to go: the search leaves now');
        $this->assertTrue($shop->leaving());
    }

    public function test_only_the_owner_ends_the_plan_or_deletes_the_shop(): void
    {
        $member = User::factory()->create();
        $member->attachShop($this->shop, ShopRole::Member);
        $this->actingAs($member);

        Livewire::test(PrivacySettings::class)->assertSee('רק בעלי החשבון יכולים לעשות את זה')->call('endPlan')->assertForbidden();
        Livewire::test(PrivacySettings::class)->set('shopName', 'רוקס')->call('deleteShop')->assertForbidden();

        $this->assertSame(ShopStatus::Active, $this->shop->fresh()->status);
    }

    public function test_the_shop_is_deleted_after_its_days_unless_kept_and_takes_all_it_holds(): void
    {
        $this->actingAs($this->owner);
        Settings::set('search.results_per_group', 7, $this->shop->id);
        app(MonthlyUsage::class)->take($this->shop->id, 'answers', 10);

        Livewire::test(PrivacySettings::class)
            ->call('$set', 'confirming', 'shop')
            ->set('shopName', 'חנות אחרת')
            ->call('deleteShop')
            ->assertSee('השם לא תואם לשם החנות.')
            ->set('shopName', ' רוקס ')
            ->call('deleteShop');

        $shop = $this->shop->fresh();
        $this->assertTrue($shop->leaving());
        $this->assertSame(30, (int) round(now()->diffInDays($shop->deletion_due_at)));
        $this->assertSame(ShopStatus::Paused, $shop->status);
        $this->assertSame('owner@example.com', $shop->deletion_asked_by);
        Mail::assertSent(ShopLeavingMail::class, fn (ShopLeavingMail $mail): bool => $mail->kind === ShopLeavingMail::ASKED && $mail->hasTo('owner@example.com'));
        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::DELETION_ASKED);

        // Changed their mind: nothing is deleted, the plan stays ended.
        Livewire::test(PrivacySettings::class)->assertSee('להשאיר את החנות')->call('keepShop');
        $this->assertFalse($this->shop->fresh()->leaving());
        $this->artisan('shops:delete-due')->assertSuccessful();
        $this->travel(31)->days();
        $this->artisan('shops:delete-due')->assertSuccessful();
        $this->assertNotNull(Shop::query()->find($this->shop->id), 'kept: not deleted on the day');

        // Asked again, and the day comes: the shop goes, with what has no foreign key.
        Livewire::test(PrivacySettings::class)->set('shopName', 'רוקס')->call('deleteShop');
        $this->travel(29)->days();
        $this->artisan('shops:delete-due')->assertSuccessful();
        $this->assertNotNull(Shop::query()->find($this->shop->id), 'not before its day');
        $this->travel(2)->days();
        $this->artisan('shops:delete-due')->assertSuccessful();

        $this->assertNull(Shop::query()->find($this->shop->id));
        $this->assertSame(0, DB::table('monthly_usage')->where('shop_id', $this->shop->id)->count());
        $this->assertSame(0, DB::table('setting_overrides')->where('scope', $this->shop->id)->count());
        $this->assertNotNull($this->owner->fresh(), 'the people stay');
        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::DELETED);
    }

    /** A platform billing the shop for a period already paid that ends at $ends (as Shopify does). */
    private function platform(CarbonInterface $ends): CancelsSubscription
    {
        $platform = new class($ends) implements CancelsSubscription
        {
            public bool $cancelled = false;

            public function __construct(private readonly CarbonInterface $ends) {}

            public function bills(Shop $shop): bool
            {
                return ! $this->cancelled;
            }

            public function keepsPaidPeriod(Shop $shop): bool
            {
                return true;
            }

            public function cancel(Shop $shop): ?CarbonInterface
            {
                $this->cancelled = true;

                return $this->ends;
            }
        };
        $this->app->instance('tests.paid_platform', $platform);
        $this->app->tag(['tests.paid_platform'], CancelsSubscription::TAG);

        return $platform;
    }

    public function test_anyone_deletes_their_own_account_but_never_leaves_a_shop_with_no_owner(): void
    {
        $viewer = User::factory()->create(['password' => 'viewer-password']);
        $viewer->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test(PrivacySettings::class)
            ->call('$set', 'confirming', 'me')
            ->assertSee('DELETE LETS')
            ->set('phrase', 'delete lets')
            ->call('deleteMe')
            ->assertSee('יש להקליד בדיוק DELETE LETS, באותיות גדולות.')
            ->set('phrase', ' DELETE LETS ')
            ->call('deleteMe')
            ->assertRedirect();

        $this->assertNull($viewer->fresh(), 'even a viewer deletes their own account');
        $this->assertNotNull($this->shop->fresh(), 'the shop stays');

        // The only owner first hands the shop over, or deletes it.
        $this->actingAs($this->owner);
        Livewire::test(PrivacySettings::class)->assertSee('אתם הבעלים היחידים של רוקס');
        $this->assertSame(['רוקס'], app(DeleteOwnAccount::class)->handle($this->owner));
        $this->assertNotNull($this->owner->fresh());
    }
}
