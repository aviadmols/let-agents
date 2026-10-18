<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Filament\Merchant\Pages\ShopAnalytics;
use App\Modules\Analytics\Models\BlockedIp;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shop's analytics screen as the designer's panel lays it out, with the search's part in it: the
 * words searched most, the searches that found nothing (each can become a synonym there), the whole
 * list searched and sorted, and an address that searched like a machine, flagged, blocked and refused.
 */
final class AnalyticsScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-for-the-analytics-screen-0001';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://shop.test', 'access_token' => self::TOKEN]));
        $this->inShop(function (): void {
            foreach ([['חיפוי קיר', 40, 0, 10, 9], ['לביד', 30, 0, 8, 6], ['מדף עץ מוכן', 12, 12, 0, 0], ['פלטה לשולחן', 9, 0, 0, 0], ['[photo]', 5, 0, 1, 0]] as [$query, $searches, $empty, $clicks, $added]) {
                SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'query' => $query, 'searches' => $searches, 'empty' => $empty, 'clicks' => $clicks, 'added' => $added, 'last_results' => $empty > 0 ? 0 : 8]);
            }
        });

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_the_words_the_failed_searches_and_a_synonym_from_the_screen(): void
    {
        Livewire::test(ShopAnalytics::class)
            ->assertSee('מידע על החנות')
            ->assertSee('91')
            ->assertSee('5 לפי תמונה')
            ->assertSee('המילים המובילות בחיפוש')
            ->assertSee('חיפוי קיר')
            ->assertSee('2.3 הוספות לסל לכל 10 חיפושים')
            ->assertSee('חיפושים שלא הצליחו')
            ->assertSee('מדף עץ מוכן')
            ->call('act', 'empty', 'מדף עץ מוכן', 'synonym', 'מדף')
            ->call('showFailed', 'noclick')
            ->assertSee('פלטה לשולחן');

        $this->assertTrue($this->inShop(fn (): bool => SearchSynonym::query()->where('term', 'מדף עץ מוכן')->where('means', 'מדף')->exists()));
    }

    public function test_the_whole_list_is_searched_and_sorted(): void
    {
        Livewire::test(ShopAnalytics::class)
            ->call('open', 'words')
            ->assertSee('כל המילים שחיפשו')
            ->assertSee('4 מתוך 4')
            ->set('search', 'לביד')
            ->assertSee('1 מתוך 1')
            ->assertDontSee('חיפוי קיר')
            ->call('open', '')
            ->assertSee('מידע על החנות');
    }

    public function test_an_address_that_searches_like_a_machine_is_flagged_blocked_and_refused(): void
    {
        $this->inShop(function (): void {
            SearchLog::query()->insert(array_map(fn (int $i): array => ['shop_id' => $this->shop->id, 'query' => 'a'.$i, 'results' => 3, 'ip' => '185.220.101.47', 'created_at' => now()->subMinutes($i % 50)], range(1, 220)));
        });

        Livewire::test(ShopAnalytics::class)
            ->assertSee('185.220.101.47')
            ->assertSee('כנראה בוט')
            ->assertSee('חריג')
            ->call('act', 'ips', '185.220.101.47', 'block')
            ->assertSee('חסום');

        $this->assertSame(['185.220.101.47'], BlockedIp::forShop($this->shop->id));
        $site = SiteKeys::site(self::TOKEN);

        $this->withServerVariables(['REMOTE_ADDR' => '185.220.101.47'])->getJson("/api/v1/search/{$site}?q=x")->assertForbidden()->assertJson(['error' => 'blocked']);
        $this->assertNotSame(403, $this->withServerVariables(['REMOTE_ADDR' => '77.127.44.9'])->getJson("/api/v1/search/{$site}?q=x")->status(), 'another address is not touched');

        Livewire::test(ShopAnalytics::class)->call('act', 'ips', '185.220.101.47', 'unblock');
        $this->assertSame([], BlockedIp::forShop($this->shop->id));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
