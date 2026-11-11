<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Contracts\StoreEditLinks;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Where a Shopify store owner edits a page, an article or the policies that an answer came from: their own admin. */
final class ShopifyEditLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_an_article_and_a_policy_open_where_the_owner_edits_them(): void
    {
        $shop = Shop::factory()->create();
        ShopifyInstall::query()->create(['shop_id' => $shop->id, 'shop_domain' => 'rocks.myshopify.com', 'access_token' => 'shpat_test', 'installed_at' => now()]);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'platform' => 'shopify', 'site_url' => 'https://rocksandgold.co.il', 'access_token' => 'lat_'.str_repeat('e', 48)]));
        $links = app(StoreEditLinks::class);

        $this->assertSame('https://admin.shopify.com/store/rocks/content/pages/123', $links->editUrl($connection, 'page', '123'));
        $this->assertSame('https://admin.shopify.com/store/rocks/content/articles/456', $links->editUrl($connection, 'post', '456'));
        $this->assertSame('https://admin.shopify.com/store/rocks/settings/legal', $links->editUrl($connection, 'policy', 'policy-789'));
        $this->assertNull($links->editUrl($connection, 'page', 'la:knowledge-own'), 'what Lets keeps is edited in Lets');
    }

    public function test_a_wordpress_post_opens_in_its_editor(): void
    {
        $shop = Shop::factory()->create();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'platform' => 'woocommerce', 'site_url' => 'https://gil.test/', 'access_token' => 'lat_'.str_repeat('f', 48)]));

        $this->assertSame('https://gil.test/wp-admin/post.php?post=42&action=edit', app(StoreEditLinks::class)->editUrl($connection, 'post', '42'));
    }
}
