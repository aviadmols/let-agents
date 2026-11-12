<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Actions\SendStoreReports;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ReportSettings;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Mail\StoreReportMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Admin\Support\StoreReport;
use App\Modules\Admin\Support\Today;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The store report by email (Tomer's design, 12.11): daily, weekly (the default), monthly or off, as the owner chooses,
 * at 08:00 on the store's clock; once a period; nothing when nothing happened.
 */
final class StoreReportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->shop = Shop::factory()->create(['name' => 'רוקס', 'timezone' => 'Asia/Jerusalem', 'content_locale' => 'he']);
        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->owner->attachShop($this->shop, ShopRole::Owner);
    }

    /** @return array<string, mixed> */
    private static function sample(string $frequency = 'weekly', string $locale = 'he'): array
    {
        return [
            'shop' => 'רוקס', 'frequency' => $frequency, 'locale' => $locale, 'range' => '9.11–15.11', 'quiet' => false,
            'searches' => 1284, 'questions' => 312, 'found_pct' => 94,
            'changes' => ['searches' => ['dir' => 1, 'pct' => 12], 'questions' => ['dir' => -1, 'pct' => 4], 'found' => ['dir' => 1, 'pts' => 3]],
            'top' => [['query' => 'טבעת זהב', 'searches' => 142, 'found' => 'טבעת סוליטר']],
            'rising' => ['query' => 'אופל', 'times' => 5, 'searches' => 40],
            'missing' => [['title' => '"מגפיים" · 41 חיפושים בלי תוצאות', 'text' => '', 'action' => 'לקשר למוצר', 'url' => 'https://agents.test/fix']],
            'sold' => [['title' => 'טבעת סוליטר', 'adds' => 31]],
            'money' => '₪2,340', 'adds' => 96, 'orders' => 12,
            'poor' => [['question' => 'איזה מבצעים יש?', 'answer' => 'מבצע חודש הספר: 6 ספרים ב-200₪.', 'downs' => 3]],
            'urls' => ['panel' => 'https://agents.test/searches', 'fixes' => 'https://agents.test/fixes', 'settings' => 'https://agents.test/settings/report', 'answers' => 'https://agents.test/questions', 'knowledge' => 'https://agents.test/about-store'],
        ];
    }

    public function test_each_report_goes_out_at_eight_on_the_stores_clock_on_its_day(): void
    {
        $monday = CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Jerusalem');
        $this->assertTrue(StoreReport::due('daily', $monday));
        $this->assertTrue(StoreReport::due('weekly', $monday));
        $this->assertFalse(StoreReport::due('monthly', $monday));
        $this->assertFalse(StoreReport::due('weekly', $monday->addHour()), 'only at 8');
        $this->assertFalse(StoreReport::due('weekly', $monday->addDay()), 'only on Mondays');
        $this->assertTrue(StoreReport::due('monthly', CarbonImmutable::parse('2026-12-01 08:00', 'Asia/Jerusalem')));
        $this->assertFalse(StoreReport::due('off', $monday));
    }

    public function test_a_report_covers_the_period_just_ended_and_compares_it_with_the_one_before(): void
    {
        $week = StoreReport::period('weekly', CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Jerusalem'));
        $this->assertSame(['2026-11-09', '2026-11-16', '2026-11-02', 'weekly:2026-11-09'], [$week['since']->toDateString(), $week['until']->toDateString(), $week['before']->toDateString(), $week['key']]);

        $month = StoreReport::period('monthly', CarbonImmutable::parse('2026-12-01 08:00', 'Asia/Jerusalem'));
        $this->assertSame(['2026-11-01', '2026-12-01'], [$month['since']->toDateString(), $month['until']->toDateString()]);

        $day = StoreReport::period('daily', CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Jerusalem'));
        $this->assertSame(['2026-11-15', '2026-11-14'], [$day['since']->toDateString(), $day['before']->toDateString()]);
    }

    public function test_changes_need_enough_before_and_the_rising_word_enough_searches(): void
    {
        $this->assertNull(StoreReport::change(50, 10), 'fewer than 20 before: no change shown');
        $this->assertSame(['dir' => 1, 'pct' => 25], StoreReport::change(50, 40));
        $this->assertSame(['dir' => -1, 'pct' => 50], StoreReport::change(20, 40));

        $this->assertSame(['query' => 'velvet', 'times' => 5, 'searches' => 50], StoreReport::rising([
            ['query' => 'linen', 'searches' => 140, 'before' => 120], ['query' => 'velvet', 'searches' => 50, 'before' => 10], ['query' => 'rare', 'searches' => 6, 'before' => 0],
        ]));
        $this->assertNull(StoreReport::rising([['query' => 'linen', 'searches' => 140, 'before' => 120]]));
        $this->assertNull(StoreReport::rising([['query' => '35059', 'searches' => 19, 'before' => 0]]), 'a SKU is no word');
        $this->assertSame(['query' => 'אמרלד', 'times' => null, 'searches' => 103], StoreReport::rising([['query' => 'אמרלד', 'searches' => 103, 'before' => 1]]), 'one search before: new');
        $this->assertSame('call me …', StoreReport::clean('call me 052-1234567'));
        $this->assertSame('write to …', StoreReport::clean('write to dana@mail.com'));
    }

    public function test_the_email_reads_in_the_stores_language_and_runs_right_to_left_in_hebrew(): void
    {
        $he = (new StoreReportMail(self::sample()))->locale('he')->render();
        $this->assertStringContainsString('dir="rtl"', $he);
        $this->assertStringContainsString('גולשים חיפשו בחנות שלכם.', $he);
        $this->assertStringContainsString('הדוח הזה מגיע אליכם כל יום שני.', $he);
        // the answers shoppers said did not help, with the two ways to fix them
        $this->assertStringContainsString('תשובות שלא עזרו', $he);
        $this->assertStringContainsString('3 גולשים סימנו שזה לא עזר', $he);
        $this->assertStringContainsString('https://agents.test/questions', $he);
        $this->assertStringContainsString('https://agents.test/about-store', $he);
        $this->assertStringContainsString('https://agents.test/settings/report', $he);
        $this->assertStringContainsString('&#9660; 4%', $he, 'a drop in grey, never red');
        $this->assertStringNotContainsString('#ff', strtolower(str_replace('#ffffff', '', $he)));

        $en = (new StoreReportMail(self::sample('monthly', 'en')))->locale('en')->render();
        $this->assertStringContainsString('dir="ltr"', $en);
        $this->assertStringContainsString('Your month in search', $en);
        $this->assertStringContainsString('You get this report on the 1st of every month.', $en);
        $this->assertSame('Your month in search: 1,284 searches, 312 questions', (new StoreReportMail(self::sample('monthly', 'en')))->envelope()->subject);
    }

    public function test_today_sends_the_report_now_to_the_address_typed(): void
    {
        $this->app->instance(StoreReport::class, new class(app(Home::class), app(Today::class)) extends StoreReport
        {
            public function of(Shop $shop, string $frequency, ?CarbonInterface $now = null): ?array
            {
                return ['frequency' => $frequency] + (new \ReflectionMethod(StoreReportTest::class, 'sample'))->invoke(null);
            }
        });
        $this->actingAs($this->owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Livewire::test(ShopOverview::class)
            ->assertSee('לשלוח עכשיו')->assertSee('בכל יום שני בבוקר')
            ->set('reportEmail', 'not-an-email')->call('sendReport')->assertNotified('הכתובת לא נראית כמו כתובת מייל.')
            ->set('reportEmail', 'aviad@example.com')->call('sendReport')->assertNotified('נשלח אל aviad@example.com');

        Mail::assertSent(StoreReportMail::class, fn (StoreReportMail $m): bool => $m->hasTo('aviad@example.com'));
    }

    public function test_the_owner_chooses_how_often_and_only_an_owner_does(): void
    {
        $this->actingAs($this->owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Livewire::test(ReportSettings::class)->assertSee('כל כמה זמן')->assertSee('owner@example.com')
            ->call('choose', 'monthly')->assertNotified('נשמר');
        $this->assertSame('monthly', StoreReport::frequency($this->shop));

        Livewire::test(ReportSettings::class)->call('choose', 'off')->assertSee('הדוח כבוי');
        $this->assertSame('off', StoreReport::frequency($this->shop));

        $member = User::factory()->create();
        $member->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($member);
        Livewire::test(ReportSettings::class)->assertSee('רק בעלי החנות')->call('choose', 'daily');
        $this->assertSame('off', StoreReport::frequency($this->shop), 'a viewer changes nothing');
    }

    public function test_due_reports_go_to_the_owners_once_a_period_and_none_when_nothing_happened(): void
    {
        $this->app->instance(StoreReport::class, new class(app(Home::class), app(Today::class)) extends StoreReport
        {
            public ?array $next = null;

            public function of(Shop $shop, string $frequency, ?CarbonInterface $now = null): ?array
            {
                return $this->next;
            }
        });
        app(StoreReport::class)->next = self::sample();
        $monday = CarbonImmutable::parse('2026-11-16 08:10', 'Asia/Jerusalem');

        // nothing goes out until the team turns the reports on
        $this->assertSame(0, app(SendStoreReports::class)->handle($monday));
        Mail::assertNothingQueued();
        Settings::set('admin.reports_sending', 1);

        $this->assertSame(0, app(SendStoreReports::class)->handle($monday->subHour()), 'not its hour');
        $this->assertSame(1, app(SendStoreReports::class)->handle($monday));
        Mail::assertQueued(StoreReportMail::class, fn (StoreReportMail $m): bool => $m->hasTo('owner@example.com'));
        $this->assertSame('weekly:2026-11-09', Settings::get('admin.report_sent', (string) $this->shop->id));
        $this->assertSame(0, app(SendStoreReports::class)->handle($monday), 'once a period');

        Settings::set('admin.report_frequency', 'off', (string) $this->shop->id);
        $this->assertSame(0, app(SendStoreReports::class)->handle($monday->addWeek()), 'off');

        Settings::set('admin.report_frequency', 'daily', (string) $this->shop->id);
        app(StoreReport::class)->next = null;
        $this->assertSame(0, app(SendStoreReports::class)->handle($monday->addDay()), 'nothing happened: nothing sent');
        $this->assertSame('daily:2026-11-16', Settings::get('admin.report_sent', (string) $this->shop->id), 'and the day is done');
    }
}
