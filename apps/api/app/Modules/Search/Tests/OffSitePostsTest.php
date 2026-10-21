<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A post Lets wrote and the team published is served at the platform's own address: a search result
 * would take the shopper off the store's site, so it is never one. The store's own posts and the
 * pages Lets read from the store's site stay.
 */
final class OffSitePostsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_8888888888888888888888888888888888888888888888888';

    public function test_a_post_served_by_lets_is_not_a_search_result(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.deck.test', 'access_token' => self::TOKEN,
        ]));
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $rows = [
                ['10', 'post', 'איך בונים דק מעץ', 'https://www.deck.test/?p=10'],
                [CatalogContent::OWN_PREFIX.'post-01abc', 'post', 'איך שומרים על דק מעץ בחורף', route('improvement.guide', ['shop' => $shop->slug, 'post' => '01abc'])],
                [CatalogContent::OWN_PREFIX.'site-77', 'page', 'משלוחים לדק מעץ', 'https://www.deck.test/shipping'],
            ];
            foreach ($rows as [$id, $type, $title, $url]) {
                CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => $type, 'external_id' => $id, 'title' => $title, 'url' => $url, 'terms' => [], 'hash' => md5($id)]);
            }
        });

        $run = app(BuildSearchIndex::class)->handle($shop->id);
        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame(2, $run->output['counts']['content']);

        $site = SiteKeys::site(self::TOKEN);
        $index = $this->getJson("/api/v1/search/{$site}/index", ['Origin' => 'https://www.deck.test'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['c:10', 'c:'.CatalogContent::OWN_PREFIX.'site-77'], array_column($index['items'], 'id'), 'the store\'s post and the page read from its site stay');

        $result = $this->getJson("/api/v1/search/{$site}?q=".urlencode('דק מעץ'), ['Origin' => 'https://www.deck.test'])->assertOk()->json();
        $this->assertNotContains('איך שומרים על דק מעץ בחורף', array_column($result['groups']['content'], 'title'));
        $this->assertContains('איך בונים דק מעץ', array_column($result['groups']['content'], 'title'));
    }
}
