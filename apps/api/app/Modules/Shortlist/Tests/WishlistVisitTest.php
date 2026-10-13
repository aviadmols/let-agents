<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A wishlist keeps the visit that last changed it, and forgets its address and session in time. */
final class WishlistVisitTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_wishvisit_token_000000000000000000000000000000000';

    public function test_a_wishlist_keeps_the_visit_that_changed_it_and_forgets_it_in_time(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN]));
        Features::override('shortlist.wishlist', true, $shop->id);
        $site = SiteKeys::site(self::TOKEN);
        $server = ['REMOTE_ADDR' => '84.229.10.21', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'];

        $this->withServerVariables($server)->postJson("/api/v1/shortlist/{$site}/wishlist", [
            'token' => 'browserVVVVVVVVVVVVVVVVVVVVVVVVVVV', 'add' => ['1'],
            'ctx' => ['sid' => 'swish00000001', 'page' => '/products/70726', 'ref' => 'https://www.facebook.com/'],
        ])->assertOk();

        $list = app(TenantContext::class)->run($shop->id, fn () => ShortlistWishlist::query()->sole());
        $this->assertSame(['84.229.10.21', 'computer', 'Chrome', 'Windows', 'swish00000001', '/products/70726', 'facebook.com'],
            [$list->ip, $list->device, $list->browser, $list->os, $list->session_id, $list->page, $list->referrer]);

        $this->travel(91)->days();
        $this->artisan('shortlist:forget-visits')->assertSuccessful();
        $list = app(TenantContext::class)->run($shop->id, fn () => ShortlistWishlist::query()->sole());
        $this->assertSame([null, null, ['1'], 'Chrome'], [$list->ip, $list->session_id, $list->items, $list->browser], 'the list stays');
    }
}
