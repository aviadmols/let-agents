<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Support\Home;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Catalog\Actions\ReadPageChoices;
use App\Modules\Catalog\Actions\ReadSitePages;
use App\Modules\Catalog\Actions\SamplePages;
use App\Modules\Catalog\Contracts\StorefrontLock;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Catalog\Support\StorefrontHome;
use App\Modules\Catalog\Tests\StorefrontLockTest;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Shopify store behind its password (a development store, and every store Shopify's reviewers
 * test on) answers every address with its password page. The scan keeps nothing of it, clears what
 * an earlier scan took from it, tells the shop in plain words, and reads the store as usual once it
 * opens.
 */
final class LockedStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private bool $open = false;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->shop = Shop::factory()->create(['domain' => 'lets-shop.test', 'platform' => ShopPlatform::Shopify]);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://lets-shop.test', 'access_token' => 'tok', 'platform' => 'shopify']));

        $this->inShop(function (): void {
            foreach (range(1, 5) as $n) {
                CatalogProduct::query()->create([
                    'external_id' => (string) $n, 'type' => 'simple', 'status' => 'publish', 'title' => "Ring {$n}",
                    'url' => "https://lets-shop.test/products/ring-{$n}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$n}", 'payload' => [],
                ]);
            }
        });

        // Locked: every address is sent to /password, which shows the password page. Open: the store.
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! $this->open) {
                return $path === '/password' ? Http::response(StorefrontLockTest::SHOPIFY_PASSWORD) : Http::response('', 302, ['Location' => 'https://lets-shop.test/password']);
            }

            return match (true) {
                str_ends_with($path, '.js') => Http::response(['selling_plan_groups' => []]),
                str_starts_with($path, '/products/') => Http::response('<html><body><main><h1>Ring '.substr($path, -1).'</h1><p>A gold ring number '.substr($path, -1).', hand made.</p><p>Full refund within 14 days of delivery.</p><p>Free shipping on every order over 300.</p></main></body></html>'),
                $path === '/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset><url><loc>https://lets-shop.test/pages/faq</loc></url></urlset>'),
                $path === '/pages/faq' => Http::response('<html><body><main><h1>FAQ</h1><p>'.str_repeat('Orders leave the studio within three working days. ', 4).'</p></main></body></html>'),
                default => Http::response('', 404),
            };
        });
    }

    public function test_a_locked_store_keeps_nothing_of_its_password_page_clears_what_was_kept_and_says_so(): void
    {
        // What an earlier scan kept from the password page, before locks were told apart.
        $this->inShop(function (): void {
            CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => ReadSitePages::PREFIX.'old-lock', 'title' => 'Lets Shop', 'url' => 'https://lets-shop.test/policies/legal-notice', 'body' => "This store is password protected. Use the password to enter the store.\nEnter store password", 'hash' => 'x']);
            CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => ReadSitePages::PREFIX.'shipping', 'title' => 'Shipping', 'url' => 'https://lets-shop.test/pages/shipping', 'body' => 'Orders leave the studio within three working days, by courier to the door.', 'hash' => 'y']);
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => BuildShopKnowledge::REPEATED, 'title' => 'x', 'body' => "- This store is password protected.\n- Use the password to enter the store.\n- Enter store password", 'terms' => ['hidden' => ['made up'], 'counts' => [], 'pages' => 12, 'products' => 39], 'hash' => 'z']);
            CatalogProductChoice::query()->create(['product_id' => CatalogProduct::query()->where('external_id', '1')->value('id'), 'fields' => [], 'checked_at' => now(), 'product_hash' => 'h1', 'failures' => 0]);
        });

        $sample = (new SamplePages(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($this->shop->id);
        $site = app(ReadSitePages::class)->handle($this->shop->id);
        $knowledge = $this->inShop(fn (): array => app(BuildShopKnowledge::class)->repeated($this->shop->id));
        $choices = (new ReadPageChoices(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($this->shop->id, 50);

        // Nothing read: one product page each told the store is locked, and no other was asked for.
        $this->assertSame([StorefrontLock::PASSWORD, 0], [$sample->output['locked'], $sample->output['read']]);
        $this->assertSame(StorefrontLock::PASSWORD, $knowledge['locked']);
        $this->assertSame(StorefrontLock::PASSWORD, $choices->output['locked']);
        $this->assertSame(3, collect(Http::recorded())->filter(fn (array $sent): bool => str_contains($sent[0]->url(), '/products/'))->count(), 'sampling, the repeated text and the choices: one page each');
        $this->assertStringContainsString('closed to visitors', (string) $sample->summary());
        $this->assertStringContainsString('come from Shopify', (string) $site->summary());
        $this->assertSame(1, $site->output['cleared']);
        $this->assertSame(StorefrontLock::PASSWORD, Run::query()->where('action', BuildShopKnowledge::ACTION)->sole()->output['locked']);

        $this->inShop(function (): void {
            $this->assertNotNull(CatalogContent::query()->where('external_id', ReadSitePages::PREFIX.'old-lock')->value('removed_at'), 'the page kept from the password page is gone');
            $this->assertNull(CatalogContent::query()->where('external_id', ReadSitePages::PREFIX.'shipping')->value('removed_at'), 'a page read while the store was open stays');
            $this->assertSame(['Shipping'], CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->pluck('title')->all(), 'nothing new from the password page');

            $repeated = CatalogContent::query()->where('external_id', BuildShopKnowledge::REPEATED)->sole();
            $this->assertNotNull($repeated->removed_at, '"Enter store password" is no longer what every product page says');
            $this->assertSame(['made up'], $repeated->terms['hidden'], 'what the team hid stays hidden');

            $this->assertNull(CatalogProductChoice::query()->sole()->checked_at, 'a reading of the password page is due again');
        });

        // The shop is told, calmly: nothing for it to do.
        $notice = collect(app(Home::class)->notices($this->shop))->firstWhere('key', 'catalog.storefront_locked');
        $this->assertInstanceOf(ShopNotice::class, $notice);
        $this->assertSame(ShopNotice::INSIGHT, $notice->kind);
        $this->assertSame('Your store is password-protected, so we read your products and pages from Shopify instead of your storefront. Some details (like how your product pages look) will be read once the store is open.', $notice->body);
        app()->setLocale('he');
        $this->assertStringContainsString('מוגנת בסיסמה', (string) $this->inShop(fn () => app(StorefrontHome::class)->notices($this->shop)[0]->body));

        // A second scan of the locked store changes nothing.
        $again = app(ReadSitePages::class)->handle($this->shop->id);
        $this->assertSame(0, $again->output['cleared']);
    }

    public function test_once_the_store_opens_the_next_scan_reads_it_and_the_notice_goes(): void
    {
        $this->inShop(fn () => app(BuildShopKnowledge::class)->repeated($this->shop->id));
        $this->assertNotNull(StorefrontLock::state($this->shop->id));

        $this->open = true;
        $sample = (new SamplePages(app(RecordsRuns::class), app(TenantContext::class), fn (int $ms) => null))->handle($this->shop->id);
        $site = app(ReadSitePages::class)->handle($this->shop->id);
        $read = $this->inShop(fn (): array => app(BuildShopKnowledge::class)->repeated($this->shop->id));

        $this->assertArrayNotHasKey('locked', $sample->output);
        $this->assertSame($sample->output['sampled'], $sample->output['read'], 'every page sampled is read');
        $this->assertSame(1, $site->output['new'], 'the FAQ');
        $this->assertSame(5, $read['pages']);
        $this->assertNull(StorefrontLock::state($this->shop->id));
        $this->assertSame([], $this->inShop(fn () => app(StorefrontHome::class)->notices($this->shop)));
        $this->inShop(fn () => $this->assertStringContainsString('Full refund within 14 days of delivery.', (string) CatalogContent::query()->where('external_id', BuildShopKnowledge::REPEATED)->value('body')));
    }

    private function inShop(callable $work): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $work);
    }
}
