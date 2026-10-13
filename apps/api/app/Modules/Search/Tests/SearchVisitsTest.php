<?php

namespace App\Modules\Search\Tests;

use App\Core\Http\Visit;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Filament\Merchant\Pages\SearchLogPage;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who searched, as the store manager reads it: each search on its own row with the address, the
 * device, browser and system, the page, where the shopper came from and the session; what was
 * clicked; and after search.visit_keep_days the address and the session are gone.
 */
final class SearchVisitsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_visits_token_0000000000000000000000000000000000';

    private const IPHONE_INSTAGRAM = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.0';

    public function test_the_visit_is_read_from_the_browser_and_what_the_script_sends(): void
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '84.229.10.20', 'HTTP_USER_AGENT' => self::IPHONE_INSTAGRAM]);
        $visit = Visit::from($request, ['sid' => 'sabc123def456', 'page' => 'https://rocksandgold.co.il/products/%D7%98%D7%91%D7%A2%D7%AA?x=1', 'ref' => 'https://l.instagram.com/?u=x']);

        $this->assertSame(['84.229.10.20', 'phone', 'Instagram', 'iOS', 'sabc123def456', '/products/טבעת', 'instagram.com'], array_values($visit));
        $this->assertSame(['computer', 'Chrome', 'Windows'], [Visit::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'), Visit::browser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'), Visit::os('Mozilla/5.0 (Windows NT 10.0; Win64)')]);
        $this->assertSame('Samsung Internet', Visit::browser('Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36'));
        $this->assertNull(Visit::from($request, ['sid' => '<script>'])['session_id'], 'a session is a token, nothing else');
    }

    public function test_each_search_is_logged_with_its_visit_what_was_clicked_and_forgotten_in_time(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN]));
        $site = SiteKeys::site(self::TOKEN);
        $ctx = ['sid' => 'svisit0000001', 'page' => '/collections/rings', 'ref' => 'https://www.google.com/search?q=x'];
        $post = fn (array $events) => $this->call('POST', "/api/v1/search/{$site}/events",
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://www.store.test', 'REMOTE_ADDR' => '84.229.10.20', 'HTTP_USER_AGENT' => self::IPHONE_INSTAGRAM],
            content: (string) json_encode(['events' => $events, 'ctx' => $ctx]));

        $post([['type' => 'search', 'q' => 'טבעת ספיר', 'results' => 12]])->assertNoContent();
        $post([['type' => 'click', 'q' => 'טבעת ספיר', 'id' => 'p:70726', 'title' => 'טבעת']])->assertNoContent();

        $row = app(TenantContext::class)->run($shop->id, fn () => SearchLog::query()->sole());
        $this->assertSame(['טבעת ספיר', 12, 'p:70726', '84.229.10.20', 'phone', 'Instagram', 'iOS', 'svisit0000001', '/collections/rings', 'google.com'],
            [$row->query, $row->results, $row->clicked, $row->ip, $row->device, $row->browser, $row->os, $row->session_id, $row->page, $row->referrer]);

        // The store manager reads it, and what one session did.
        $manager = User::factory()->create();
        $manager->attachShop($shop, ShopRole::Owner);
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);
        Livewire::test(SearchLogPage::class)->assertSee('טבעת ספיר')->assertSee('84.229.10.20')->assertSee('google.com')
            ->set('session', 'svisit0000001')->assertSee('טבעת ספיר')
            ->set('session', 'snothere000001')->assertDontSee('טבעת ספיר');

        // 90 days on: the address and the session are gone, the search stays.
        $this->travel(91)->days();
        $this->artisan('search:forget-visits')->assertSuccessful();
        $row = app(TenantContext::class)->run($shop->id, fn () => SearchLog::query()->sole());
        $this->assertSame([null, null, 'טבעת ספיר', 'phone'], [$row->ip, $row->session_id, $row->query, $row->device]);
    }
}
