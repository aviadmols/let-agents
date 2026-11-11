<?php

namespace App\Modules\Shoppers\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Shoppers\Models\ShopperVerification;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The nightly prune of the codes sent to confirm a shopper's email runs with no shop in the tenant context, as the
 * scheduler runs it, and removes every shop's codes a day past their time (it failed every night, 9-11.11).
 */
final class VerificationPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_codes_of_every_shop_are_pruned_with_no_shop_in_context(): void
    {
        $shops = [Shop::factory()->create(), Shop::factory()->create()];
        foreach ($shops as $i => $shop) {
            app(TenantContext::class)->run($shop->id, function () use ($shop, $i): void {
                foreach (['old' => now()->subDays(3), 'fresh' => now()->addMinutes(10)] as $kind => $at) {
                    ShopperVerification::query()->create([
                        'shop_id' => $shop->id, 'visitor_hash' => hash('sha256', $kind.$i), 'channel' => 'email',
                        'contact_hash' => hash('sha256', 'c'.$kind.$i), 'contact' => 'a'.$i.'@example.com', 'code_hash' => hash('sha256', '123456'),
                        'attempts' => 0, 'expires_at' => $at,
                    ]);
                }
            });
        }

        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [ShopperVerification::class]]));
        $this->assertSame(2, app(TenantContext::class)->runUnscoped(fn () => ShopperVerification::query()->count()), 'the fresh code of each shop stays');
    }
}
