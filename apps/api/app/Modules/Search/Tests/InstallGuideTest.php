<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The searches screen says how to put the search on the site, filled in with the shop's own
 * details: what is ready, the steps in WordPress, the field it attaches to, a preview link, and
 * the code for a site without the plugin.
 */
final class InstallGuideTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_ffffffffffffffffffffffffffffffffffffffffffffffff';

    public function test_the_shop_manager_sees_how_to_install_with_their_own_details(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $page = $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$shop->slug}/search/terms")->assertOk();

        $page->assertSee(__('search::ui.install.heading'));
        $page->assertSee('WooCommerce');
        $page->assertSee('input[name=&quot;s&quot;]', false);
        $page->assertSee(SiteKeys::site(self::TOKEN), false);
        $page->assertSee('https://www.store.test/?let_agents_preview='.$connection->previewKey(), false);
        $page->assertSee('let-agents-search.js', false);
    }

    public function test_before_the_site_is_connected_the_code_waits(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$shop->slug}/search/terms")
            ->assertOk()
            ->assertSee(__('search::ui.install.checks.connected.no'))
            ->assertSee(__('search::ui.install.other.no_key'))
            ->assertDontSee('let-agents-search.js', false);
    }
}
