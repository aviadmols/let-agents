<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Admin\Models\User;
use App\Modules\Runs\Filament\Merchant\Widgets\FirstScanCard;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Runs\Support\ScanTimes;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new shop watching its scan: the step at work says how far it is as it goes (so many of so many, for how long),
 * and once the site has been read, when the whole scan should be done. Code only: counts the modules give, and
 * how long each step took in the scans that ended.
 */
final class ScanProgressTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{done: int, total: int|null}> */
    public static array $counts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        self::$counts = [];
        // the counts the test sets, alone: the modules' own are each tried in their own tests
        $tags = new \ReflectionProperty($this->app, 'tags');
        $tags->setValue($this->app, [ScanProgress::TAG => [ScanProgressTestSource::class]] + $tags->getValue($this->app));
    }

    public function test_the_step_at_work_shows_how_far_it_is_and_the_end_shows_once_the_site_is_read(): void
    {
        $shop = Shop::factory()->create();
        Bus::fake();
        FirstScan::start($shop);
        FirstScan::mark($shop->id, 'catalog', FirstScan::RUNNING);
        self::$counts = ['catalog' => ['done' => 40, 'total' => null]];

        $this->card($shop)
            ->assertSee('40 מוצרים עד עכשיו')
            ->assertSee('אחרי שכל האתר ייקרא')
            ->assertDontSee('צפוי להסתיים');

        FirstScan::mark($shop->id, 'catalog', FirstScan::DONE);
        FirstScan::mark($shop->id, 'sample', FirstScan::DONE);
        FirstScan::mark($shop->id, 'site', FirstScan::DONE);
        FirstScan::mark($shop->id, 'knowledge', FirstScan::RUNNING);
        self::$counts = ['catalog' => ['done' => 142, 'total' => 142], 'knowledge' => ['done' => 3, 'total' => 12], 'products' => ['done' => 0, 'total' => 142]];

        $this->card($shop)
            ->assertSee('3 מתוך 12 דפים')
            ->assertSee('role="progressbar"', false)
            ->assertSee('צפוי להסתיים בסביבות');
    }

    public function test_the_time_left_comes_from_how_fast_the_step_goes_and_how_long_the_others_took(): void
    {
        $this->travelTo(now()->startOfMinute());
        // 10 of 100 in 60 seconds: the other 90 in 540
        $this->assertSame(540, ScanTimes::left('products', now()->subSeconds(60), 10, 100));
        // none counted yet: what it usually takes, less what it spent
        $this->assertSame(6 * 100 - 60, ScanTimes::left('products', now()->subSeconds(60), 0, 100));
        // never less than half a minute while it works
        $this->assertSame(30, ScanTimes::left('search', now()->subHour(), 0, null));

        // a scan that ended teaches the next: 200 products in 400 seconds, 2 a product
        ScanTimes::learn('products', 400, 200);
        $this->assertSame(200, ScanTimes::whole('products', 100));
        ScanTimes::learn('search', 90, 0);
        $this->assertSame(90, ScanTimes::whole('search', null));
    }

    public function test_a_while_is_said_in_words(): void
    {
        app()->setLocale('en');
        $this->assertSame('a minute', FirstScan::duration(1));
        $this->assertSame('45 minutes', FirstScan::duration(45));
        $this->assertSame('an hour', FirstScan::duration(60));
        $this->assertSame('2 hours and 5 minutes', FirstScan::duration(125));
    }

    private function card(Shop $shop): mixed
    {
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        return Livewire::test(FirstScanCard::class);
    }
}

/** A module's counts, as the test sets them. */
final class ScanProgressTestSource implements ScanProgress
{
    public function scanProgress(Shop $shop, array $began): array
    {
        return ScanProgressTest::$counts;
    }
}
