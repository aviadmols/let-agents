<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Search\Support\SearchFirstSteps;
use App\Modules\Search\Support\StorefrontLock;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Let’s is live in the store from the first page that loaded it outside the preview: a new store had no suggestion to see
 * or click and one person searching, and stayed "In preview" with its search on (17.11). A page in the preview is not.
 */
final class LiveInStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_that_loaded_lets_outside_the_preview_makes_it_live(): void
    {
        $shop = Shop::factory()->create();
        $live = fn (): bool => collect(app(TenantContext::class)->run($shop->id, fn (): array => app(SearchFirstSteps::class)->steps($shop)))->firstWhere('key', 'on')->done;
        $visit = fn (bool $preview, int $n) => app(TenantContext::class)->run($shop->id, fn () => AnalyticsVisit::query()->create([
            'shop_id' => $shop->id, 'session_id' => 's'.$n, 'visitor_hash' => 'v'.$n, 'visit_number' => 1, 'started_at' => now(), 'last_at' => now(),
            'source' => 'direct', 'device' => 'mobile', 'os' => 'iOS', 'browser' => 'Safari', 'pages' => 1, 'steps' => 1, 'did' => [], 'preview' => $preview, 'ip' => '10.0.0.'.$n,
        ]));

        $this->assertFalse($live(), 'nothing yet');
        $visit(true, 1);
        $this->assertFalse($live(), 'the team in the preview is no shopper');
        $visit(false, 2);
        $this->assertTrue($live(), 'a page of the store that loaded Let’s');
    }

    /**
     * A Shopify store loads Let’s only through its app embed: its team's own look in the preview is proof the embed is on,
     * and the step is done (it stayed open after the embed was saved, App Store review 17.11). Behind its password, "See it
     * as a shopper" opens the search check here instead of the password page.
     */
    public function test_a_shopify_store_is_on_from_the_first_page_that_loaded_lets_and_a_locked_one_is_seen_here(): void
    {
        $shop = Shop::factory()->create(['platform' => ShopPlatform::Shopify, 'domain' => 'lets-review.myshopify.com']);
        $steps = fn (): Collection => collect(app(TenantContext::class)->run($shop->id, fn (): array => app(SearchFirstSteps::class)->steps($shop)));

        $this->assertFalse($steps()->firstWhere('key', 'on')->done, 'nothing yet');
        $this->assertStringStartsWith('https://lets-review.myshopify.com/', (string) $steps()->firstWhere('key', 'look')->url, 'an open store is seen in the store');

        app(TenantContext::class)->run($shop->id, fn () => AnalyticsVisit::query()->create([
            'shop_id' => $shop->id, 'session_id' => 's1', 'visitor_hash' => 'v1', 'visit_number' => 1, 'started_at' => now(), 'last_at' => now(),
            'source' => 'direct', 'device' => 'desktop', 'os' => 'macOS', 'browser' => 'Chrome', 'pages' => 1, 'steps' => 1, 'did' => [], 'preview' => true, 'ip' => '10.0.0.1',
        ]));
        $this->assertTrue($steps()->firstWhere('key', 'on')->done, 'the embed loaded Let’s: it is on');

        $this->app->instance(StorefrontLock::class, new class extends StorefrontLock
        {
            public function locked(Shop $shop): bool
            {
                return true;
            }
        });
        $this->assertStringContainsString('/search/check', (string) $steps()->firstWhere('key', 'look')->url, 'behind its password: the search check here');
    }
}
