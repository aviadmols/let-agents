<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ConnectionSettings;
use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A Shopify store on "Connection and plan": the app installed, its plan and when it renews, and no plugin. */
final class ShopifyConnectionFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_and_its_plan_show_and_the_plugin_does_not(): void
    {
        $shop = Shop::factory()->create(['platform' => ShopPlatform::Shopify, 'domain' => 'rocks.myshopify.com']);
        $owner = User::factory()->create();
        $owner->attachShop($shop, ShopRole::Owner);
        $this->actingAs($owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);
        app(TenantContext::class)->set($shop->id);

        $this->travelTo(now()->setDate(2026, 10, 19)->startOfDay());
        ShopifyInstall::query()->create([
            'shop_id' => $shop->id, 'shop_domain' => 'rocks.myshopify.com', 'access_token' => 'shpat_test', 'installed_at' => now()->subDays(40),
            'subscription_status' => ShopifyInstall::ACTIVE, 'subscribed_at' => now()->subDays(40),
        ]);

        Livewire::test(ConnectionSettings::class)
            ->assertSee('מחובר')
            ->assertSee('Shopify · האפליקציה מותקנת')
            ->assertSee('rocks.myshopify.com')
            ->assertSee('מתחדש 8.11.2026')
            ->assertDontSee('להוריד את התוסף')
            ->assertDontSee('ליצור טוקן חיבור');
    }
}
