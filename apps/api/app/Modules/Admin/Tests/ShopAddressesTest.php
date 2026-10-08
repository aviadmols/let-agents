<?php

namespace App\Modules\Admin\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Panels\MerchantPanelProvider;
use App\Modules\Tenancy\Actions\CreateShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\ListShops;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Tables\ShopsTable;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each shop's panel on its own address once the platform has a shop domain (gueta.agents.lets.co.il),
 * and the shops list the operator starts from.
 */
final class ShopAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_a_shop_domain_each_shop_is_on_its_own_address(): void
    {
        config(['upsell.shop_domain' => 'agents.lets.co.il']);
        $panel = (new MerchantPanelProvider(app()))->panel(Panel::make());

        $this->assertSame('{tenant:slug}.agents.lets.co.il', $panel->getTenantDomain());

        config(['upsell.shop_domain' => null]);
        $this->assertNull((new MerchantPanelProvider(app()))->panel(Panel::make())->getTenantDomain(), 'without one, /merchant/{shop} as before');
    }

    public function test_a_shop_address_is_a_web_address_label_and_never_a_platform_name(): void
    {
        $shop = app(CreateShop::class)->handle(['name' => 'www', 'domain' => 'www.example.co.il', 'platform' => 'woocommerce']);

        $this->assertNotSame('www', $shop->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $shop->slug);
    }

    public function test_the_shops_list_shows_the_store_its_address_connection_and_products(): void
    {
        $shop = Shop::factory()->create(['domain' => 'shop.guetaavigdor.co.il', 'slug' => 'gueta-avigdor']);
        app(TenantContext::class)->runUnscoped(function () use ($shop): void {
            DB::table('store_connections')->insert(['id' => (string) str()->ulid(), 'shop_id' => $shop->id, 'platform' => 'woocommerce', 'site_url' => 'https://shop.guetaavigdor.co.il', 'access_token' => encrypt('x'), 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['1', '2'] as $id) {
                DB::table('catalog_products')->insert(['id' => (string) str()->ulid(), 'shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'p'.$id, 'hash' => 'h'.$id, 'payload' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListShops::class)
            ->assertCanSeeTableRecords([$shop])
            ->assertTableColumnStateSet('products_count', 2, $shop)
            ->assertTableColumnStateSet('connected', true, $shop)
            ->assertSee('shop.guetaavigdor.co.il');

        $this->assertStringContainsString('/merchant/gueta-avigdor', (string) ShopsTable::merchantUrl($shop));
    }
}
