<?php

namespace App\Modules\Demo\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Actions\AlertDemos;
use App\Modules\Demo\Actions\RetryDemo;
use App\Modules\Demo\Actions\SweepDemos;
use App\Modules\Demo\Filament\Operator\Pages\Demos;
use App\Modules\Demo\Jobs\PrepareDemoJob;
use App\Modules\Demo\Mail\DemoMail;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\DemoProgress;
use App\Modules\Demo\Support\PublicStoreFeed;
use App\Modules\Tenancy\Contracts\CreatesShops;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A demo, as whoever asks for it goes through it: the address and the email, the code, a demo store of its own
 * (status demo, so nothing real or scheduled is touched), held to the demo's limits, read from what the store
 * publishes, shown on our page with the floating search, and deleted with everything when it ends.
 */
final class DemoFlowTest extends TestCase
{
    use RefreshDatabase;

    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::fake(['https://store.test/' => Http::response('<html lang="he"><title>חנות בדיקה</title><link rel="stylesheet" href="/wp-content/themes/store.css"></html>')]);
    }

    private function start(array $input = []): TestResponse
    {
        return $this->post('/demo', $input + ['url' => 'https://www.store.test/shop', 'email' => 'Owner@Store.test', 'kind' => 'store', 'matters' => 'look', 'language' => 'he', 'consent' => '1']);
    }

    /** A demo asked for and its code typed: its store built, its preparation waiting in the queue. */
    private function verified(array $input = []): DemoSite
    {
        $this->start($input);
        $site = DemoSite::query()->latest('id')->firstOrFail();
        $this->post('/demo/'.$site->token.'/verify', ['code' => Mail::sent(DemoMail::class, fn (DemoMail $m): bool => $m->kind === 'code' && $m->site->is($site))->last()->data['code']]);

        return $site->refresh();
    }

    public function test_the_address_and_the_email_send_a_code_and_nothing_is_built_before_it(): void
    {
        $this->get('/demo')->assertOk()->assertSee('data-demo-start', false)->assertSee('See Lets on your store');
        $this->get('/demo?lang=he')->assertOk()->assertSee('See Lets on your store')->assertDontSee('ראו את Lets', false);

        $this->start()->assertRedirect();

        $site = DemoSite::query()->sole();
        $this->assertSame(['store.test', 'owner@store.test', DemoSite::PENDING, ['kind' => 'store', 'matters' => 'look', 'language' => 'he']], [$site->host, $site->email, $site->status, $site->choices]);
        Mail::assertSent(DemoMail::class, fn (DemoMail $m): bool => $m->kind === 'code' && $m->locale === 'en' && $m->hasTo('owner@store.test') && preg_match('/^\d{6}$/', $m->data['code'] ?? '') === 1);
        $this->assertSame(0, Shop::query()->count(), 'no store until the email is proven');
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-verify', false);
    }

    public function test_a_site_on_neither_shopify_nor_wordpress_gets_no_demo_and_is_told_why(): void
    {
        Http::fake(['https://wix.test/' => Http::response('<html><title>A Wix store</title><script src="https://static.wixstatic.com/x.js"></script></html>'),
            'https://wix.test/*' => Http::response('Not found', 404)]);

        $this->start(['url' => 'https://wix.test'])->assertSessionHas('demo_error', 'not_supported');
        $this->assertSame(0, DemoSite::query()->count(), 'no code sent, nothing kept');
        $this->get('/demo')->assertOk();
        $this->assertStringContainsString('Shopify and WordPress', __('demo::page.errors.not_supported'));
    }

    public function test_a_store_already_on_lets_or_no_address_is_no_demo(): void
    {
        Shop::factory()->create(['domain' => 'store.test']);

        $this->start()->assertSessionHas('demo_error', 'already');
        $this->start(['url' => 'not an address'])->assertSessionHas('demo_error', 'bad_url');
        $this->assertSame(0, DemoSite::query()->count());
    }

    public function test_the_code_builds_a_demo_store_of_its_own_held_to_the_demos_limits(): void
    {
        $this->start();
        $site = DemoSite::query()->sole();
        $code = Mail::sent(DemoMail::class)->first()->data['code'];

        $this->post('/demo/'.$site->token.'/verify', ['code' => '000000'])->assertSessionHas('demo_result', 'wrong');
        $this->post('/demo/'.$site->token.'/verify', ['code' => $code])->assertRedirect('/demo/'.$site->token);

        $site->refresh();
        $shop = Shop::query()->sole();
        $this->assertSame([DemoSite::SCANNING, ShopStatus::Demo, 'store.test'], [$site->status, $shop->status, $shop->domain]);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shop->id)->sole());
        $this->assertSame('demo', $connection->platform);
        $this->assertContains(parse_url((string) config('app.url'), PHP_URL_HOST), $connection->site_info['hosts'], 'the drawer answers on our demo page');
        $this->assertNotNull(StoreConnection::forSite((string) $connection->site_key), 'a demo store has a storefront');
        $this->assertEquals([300, (float) Settings::get('demo.budget_usd'), true, false, 'picture'],
            [Settings::get('catalog.max_products', $shop->id), Settings::get('ai.shop_monthly_cap_usd', $shop->id), Settings::get('ai.shop_cap_stops_shoppers', $shop->id), Settings::get('search.show_cart', $shop->id), Settings::get('search.similar_by', $shop->id)]);
        $this->assertFalse(Features::enabled('enrichment.nightly', $shop->id), 'nothing a night would do');
        $this->assertTrue(Features::enabled('search.photos', $shop->id), 'looks matter: photo search');
        Queue::assertPushed(PrepareDemoJob::class);
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-scan', false);
    }

    public function test_the_ready_demo_shows_the_floating_search_and_ends_with_its_store_deleted(): void
    {
        $this->start();
        $site = DemoSite::query()->sole();
        $this->post('/demo/'.$site->token.'/verify', ['code' => Mail::sent(DemoMail::class)->first()->data['code']]);
        $site->refresh()->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 12])->save();

        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-bar', false)->assertSee('/demo/'.$site->token.'/phone', false);
        $this->get('/demo/'.$site->token.'/phone')->assertOk()->assertSee("launcher: 'float'", false)
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self';")
            ->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Try the search')->assertSee('locale: "he"', false)->assertDontSee('data-hint', false);

        $this->travel(8)->days();
        app(SweepDemos::class)->handle();

        $this->assertSame(DemoSite::EXPIRED, $site->fresh()->status);
        $this->assertSame(0, Shop::query()->count(), 'the demo store and all it holds are gone');
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-ended', false);
    }

    public function test_a_store_that_connects_for_real_takes_its_domain_from_its_demo(): void
    {
        $demo = app(CreatesShops::class)->handle(['name' => 'demo', 'domain' => 'store.test', 'platform' => 'woocommerce', 'status' => 'demo']);

        $real = app(CreatesShops::class)->handle(['name' => 'Store', 'domain' => 'https://www.store.test', 'platform' => 'shopify']);

        $this->assertNull(Shop::query()->find($demo->id), 'the demo went');
        $this->assertSame('store.test', $real->domain);
    }

    public function test_a_shopify_stores_public_feed_reads_as_any_feed(): void
    {
        Http::fake(['https://store.test/products.json*' => Http::sequence()
            ->push(['products' => [[
                'id' => 7, 'title' => 'טבעת זהב', 'handle' => 'ring', 'body_html' => '<p>טבעת <b>זהב</b> 14K</p>', 'vendor' => 'Rocks', 'product_type' => 'טבעות', 'tags' => ['זהב'],
                'variants' => [['id' => 70, 'title' => '52', 'option1' => '52', 'price' => '890.00', 'compare_at_price' => '990.00', 'available' => true], ['id' => 71, 'title' => '54', 'option1' => '54', 'price' => '890.00', 'available' => false]],
                'options' => [['name' => 'מידה', 'values' => ['52', '54']]], 'images' => [['src' => 'https://cdn.test/ring.jpg']],
            ]]])
            ->push(['products' => []])]);
        $connection = (new StoreConnection)->forceFill(['site_url' => 'https://store.test', 'site_info' => ['demo' => ['platform' => 'shopify', 'currency' => 'ILS']]]);

        $record = iterator_to_array(app(PublicStoreFeed::class)->products($connection), false)[0][0];

        $this->assertSame(['7', 'טבעת זהב', 'https://store.test/products/ring', 'variable', 'טבעת זהב 14K'], [$record['external_id'], $record['title'], $record['url'], $record['type'], $record['description']]);
        $this->assertSame(['890', '990', true, true], [$record['price']['price'], $record['price']['regular_price'], $record['price']['on_sale'], $record['stock']['in_stock']]);
        $this->assertSame([['name' => 'מידה', 'value' => '52']], $record['variations'][0]['attributes']);
    }

    public function test_each_demo_shows_what_it_cost_building_it_and_its_visitors_apart_and_keeps_it_when_it_ends(): void
    {
        $site = $this->verified();
        $spend = fn (string $agent, float $usd) => DB::table('ai_usage')->insert(['shop_id' => $site->shop_id, 'agent' => $agent, 'action' => $agent, 'provider' => 'openai',
            'model' => 'm', 'cost_usd' => $usd, 'day' => now()->toDateString(), 'created_at' => now()]);
        $spend('taxonomy.tagger', 0.3);
        $spend('assistant.site_answerer', 0.05);

        $this->assertSame(['build' => 0.3, 'visitors' => 0.05], SweepDemos::costs($site->shop_id));
        $page = app(Demos::class);
        $this->assertSame(0.35, $page->demos()->firstWhere('id', $site->id)->spent);

        SweepDemos::end($site->refresh());
        $this->assertSame(0.35, $site->fresh()->cost_usd, 'the store and its ledger went; what it cost stays on its row');
        $this->assertSame(0.35, $page->demos()->firstWhere('id', $site->id)->spent);
    }

    public function test_the_scan_screen_says_what_was_read_in_its_own_words_and_the_demos_limit_never_what_changed(): void
    {
        $site = $this->verified();
        app()->setLocale('en');
        $learned = [
            ['part' => 'products', 'text' => 'Read 300, 300 new, 0 changed. No product was marked removed. To read the rest, raise the limit in the settings.'],
            ['part' => 'pages', 'text' => 'Site pages: 10 new, 0 changed, 0 left the site.'],
            ['part' => 'tags', 'text' => 'Sampled 277 products and found 4 candidate keys.'],
        ];

        $learned[] = ['part' => 'knowledge', 'text' => '2 answers prepared.'];

        $said = DemoProgress::said($site->shop, $learned, ['products' => 300, 'pages_read' => 10, 'tags' => 0, 'answers' => 2]);

        $this->assertSame(['products', 'pages', 'knowledge'], array_column($said, 'part'), 'a part with nothing counted says nothing');
        $this->assertStringNotContainsString('2', $said[2]['text'], 'the knowledge centre is said without its few answers counted');
        $this->assertStringContainsString('300 products read.', $said[0]['text']);
        $this->assertStringContainsString('may show only part of the store', $said[0]['text'], 'at the demo\'s limit: said so');
        $this->assertSame('10 pages and posts read.', $said[1]['text']);
        foreach ($said as $line) {
            $this->assertDoesNotMatchRegularExpression('/changed|removed|settings/i', $line['text']);
        }
    }

    public function test_every_demo_reads_its_pictures_and_its_vocabulary_advances_unless_pictures_are_off(): void
    {
        $content = $this->verified(['matters' => 'content']);
        $this->get('/demo/'.$content->token)->assertOk()->assertSee('data-row="images"', false)->assertSee('data-row="knowledge"', false);
        $this->assertTrue(Features::enabled('search.photos', $content->shop_id), 'a search by how a product looks finds it, whatever matters most');
        $this->assertTrue(Features::enabled('enrichment.schema_auto_advance', $content->shop_id), 'its vocabulary is applied: filters and tags');
        $this->assertSame('meaning', Settings::get('search.similar_by', $content->shop_id), 'what matters still sets how alike is compared');

        Features::override('demo.pictures', false);
        $this->travel(1)->minutes();
        $off = $this->verified(['url' => 'https://store.test', 'email' => 'other@store.test', 'matters' => 'look']);
        $this->get('/demo/'.$off->token)->assertOk()->assertDontSee('data-row="images"', false);
    }

    public function test_a_woocommerce_stores_variants_come_with_their_colours_and_sizes_by_name(): void
    {
        Http::fake(['https://store.test/wp-json/wc/store/v1/products*' => Http::sequence()
            ->push([[
                'id' => 5, 'name' => 'Old Skool', 'type' => 'variable', 'permalink' => 'https://store.test/p/old-skool', 'is_in_stock' => true,
                'prices' => ['price' => '29990', 'regular_price' => '29990', 'currency_code' => 'ILS', 'currency_minor_unit' => 2],
                'attributes' => [
                    ['name' => 'צבע', 'taxonomy' => 'pa_color', 'has_variations' => true, 'terms' => [['name' => 'Black/White', 'slug' => 'black-white']]],
                    ['name' => 'מידה', 'taxonomy' => 'pa_size', 'has_variations' => true, 'terms' => [['name' => '42', 'slug' => '42'], ['name' => '42.5', 'slug' => '42-5']]],
                ],
                'variations' => [['id' => 51, 'attributes' => [['name' => 'צבע', 'value' => 'black-white'], ['name' => 'מידה', 'value' => '42-5']]]],
            ]])
            ->push([])]);
        $connection = (new StoreConnection)->forceFill(['site_url' => 'https://store.test', 'site_info' => ['demo' => ['platform' => 'woocommerce']]]);

        $record = iterator_to_array(app(PublicStoreFeed::class)->products($connection), false)[0][0];

        $this->assertSame([['name' => 'צבע', 'value' => 'Black/White'], ['name' => 'מידה', 'value' => '42.5']], $record['variations'][0]['attributes']);
        $this->assertSame(['51', '299.9', true], [$record['variations'][0]['external_id'], $record['variations'][0]['price']['price'], $record['variations'][0]['stock']['in_stock']]);
    }

    public function test_the_demo_says_it_is_a_demo_and_how_much_of_the_store_it_read_and_the_team_can_delete_it(): void
    {
        $site = $this->verified();
        $site->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 300, 'store_products' => 1240, 'pictures' => 0])->save();

        // plainly on the phone's screen, instead of a picture of the store, in the demo's language and Lets' fonts
        $this->get('/demo/'.$site->token.'/phone')->assertOk()->assertSee('data-intro', false)->assertDontSee('<img class="site"', false)
            ->assertSee('This is only a demo, so results may be partial.')->assertSee('It read 300 of the store&#039;s 1,240 products.', false)
            ->assertSee('family=Google+Sans', false);

        $shop = $site->shop_id;
        app(Demos::class)->remove($site->id);
        $this->assertNull(DemoSite::query()->find($site->id), 'gone from the list');
        $this->assertNull(Shop::query()->find($shop), 'with its store');
    }

    public function test_the_demos_list_shows_how_much_each_read_whether_its_pictures_were_looked_at_and_what_it_cost(): void
    {
        $site = $this->verified();
        $site->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 300, 'store_products' => 1240, 'pictures' => 0])->save();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        foreach (['he', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->get('/operator/demos')->assertOk()->assertSee('data-pictures="0"', false)->assertSee(number_format(1240))
                ->assertSee(__('demo::operator.pictures_none'))->assertSee(__('demo::operator.rescan'))->assertSee(__('demo::operator.delete'))
                ->assertSee('model-calls?shop='.$site->shop_id, false)->assertSee('data-runs', false);
        }
    }

    public function test_the_team_is_told_once_by_email_of_a_demo_that_failed_or_a_scan_that_stopped(): void
    {
        $operator = User::factory()->operator()->create();
        $failed = $this->verified();
        $failed->forceFill(['status' => DemoSite::FAILED, 'error' => 'unreachable'])->save();

        $this->travel(1)->minutes();
        Http::fake(['https://other.test/' => Http::response('<html><title>Other</title><script src="https://cdn.shopify.com/s/x.js"></script></html>')]);
        $stalled = $this->verified(['url' => 'https://other.test', 'email' => 'other@store.test']);
        $stalled->forceFill(['scan_at' => now()])->save();

        $this->assertSame(1, app(AlertDemos::class)->handle(), 'the failed one; the other has only just begun');
        Mail::assertSent(DemoMail::class, fn (DemoMail $m): bool => $m->kind === 'alert' && $m->hasTo($operator->email) && str_contains($m->data['what'], 'Your site did not answer us'));

        $this->travel(AlertDemos::STALLED_MINUTES + 1)->minutes();
        $this->assertSame(1, app(AlertDemos::class)->handle(), 'the stalled one now; the failed one was told already');
        $this->assertSame(0, app(AlertDemos::class)->handle(), 'each problem once');
        $this->assertSame(2, Mail::sent(DemoMail::class, fn (DemoMail $m): bool => $m->kind === 'alert')->count());
    }

    public function test_the_team_scans_a_demo_again_from_the_start(): void
    {
        $site = $this->verified();
        $old = $site->shop_id;
        $site->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 12, 'read_at' => now(), 'scan_at' => now(), 'emailed_at' => now()])->save();
        Queue::fake();

        app(Demos::class)->rescan($site->id);

        $site->refresh();
        $this->assertSame([DemoSite::SCANNING, null, null, null], [$site->status, $site->read_at, $site->emailed_at, $site->expires_at]);
        $this->assertNull(Shop::query()->find($old), 'its store went with all it read');
        $this->assertNotSame($old, $site->shop_id);
        Queue::assertPushed(PrepareDemoJob::class);
    }

    public function test_the_language_chosen_is_the_stores_and_its_searchs(): void
    {
        $this->start(['language' => 'xx'])->assertSessionHas('demo_error', 'choose');

        $site = $this->verified(['language' => 'en']);
        $this->assertSame('en', $site->shop->content_locale);

        $site->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 3])->save();
        $this->get('/demo/'.$site->token.'/phone')->assertOk()->assertSee('locale: "en"', false)->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_the_scan_screen_says_its_lines_in_the_demos_language_whatever_the_apps(): void
    {
        $site = $this->verified();
        app()->setLocale('he');

        $this->getJson('/demo/'.$site->token.'/progress')->assertOk();

        $this->assertSame(DemoSite::LOCALE, app()->getLocale(), 'what the scan learned is said in English, as the rest of the demo');
    }

    public function test_the_scan_screen_shows_each_step_of_the_preparation(): void
    {
        $site = $this->verified();
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-live', false)->assertSee('Now: Reading the site')
            ->assertSee('This takes about 15 minutes', false);
        $this->getJson('/demo/'.$site->token.'/progress')->assertJsonPath('now', 'read')->assertJsonPath('since', $site->verified_at->toIso8601String());
        $this->getJson('/demo/'.$site->token.'/progress')->assertOk()
            ->assertJsonPath('steps', [['key' => 'read', 'state' => 'running'], ['key' => 'scan', 'state' => 'waiting']])
            ->assertJsonPath('slow', false)->assertJsonPath('error', null);

        Http::fake(['https://store.test/products.json*' => Http::response('<html></html>'), 'https://store.test/wp-json/*' => Http::response([['id' => 1]])]);
        (new PrepareDemoJob($site->id))->handle(app(TenantContext::class));

        $site->refresh();
        $this->assertNotNull($site->read_at);
        $this->assertNotNull($site->scan_at, 'the scan began');
        $this->assertSame('woocommerce', $site->platform);
        $this->getJson('/demo/'.$site->token.'/progress')
            ->assertJsonPath('steps', [['key' => 'read', 'state' => 'done'], ['key' => 'scan', 'state' => 'done']]);
    }

    public function test_a_preparation_that_stopped_is_sent_again_then_shown_as_a_problem_with_what_to_do(): void
    {
        $site = $this->verified();
        $this->travel(5)->minutes();
        $this->getJson('/demo/'.$site->token.'/progress')->assertJsonPath('slow', true);

        Queue::fake();
        app(SweepDemos::class)->handle();
        Queue::assertPushed(PrepareDemoJob::class, 1);

        $site->forceFill(['attempts' => 3])->save();
        $this->travel(5)->minutes();
        app(SweepDemos::class)->handle();
        $this->assertSame([DemoSite::FAILED, 'stuck'], [$site->fresh()->status, $site->fresh()->error]);

        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-problem="stuck"', false)->assertSee('name="url"', false);
        $this->getJson('/demo/'.$site->token.'/progress')->assertJsonPath('error', 'stuck')->assertJsonPath('steps.0.state', 'failed');
    }

    public function test_after_a_problem_the_demo_is_tried_again_at_the_address_corrected(): void
    {
        $site = $this->verified();
        $old = $site->shop_id;
        $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'unreachable'])->save();
        Http::fake(['https://shop.test/' => Http::response('<html><title>Shop</title><link href="/wp-content/x.css"></html>')]);

        $this->post('/demo/'.$site->token.'/again', ['url' => 'not an address'])->assertSessionHas('demo_error', 'bad_url');
        $this->get('/demo/'.$site->token)->assertSee('data-retry-error="bad_url"', false);

        Queue::fake();
        $this->post('/demo/'.$site->token.'/again', ['url' => 'https://shop.test'])->assertRedirect('/demo/'.$site->token);

        $site->refresh();
        $this->assertSame([DemoSite::SCANNING, 'shop.test', 1, null], [$site->status, $site->host, $site->retries, $site->error]);
        $this->assertNull(Shop::query()->find($old), 'the failed demo\'s store went');
        $this->assertSame('shop.test', $site->shop->domain);
        Queue::assertPushed(PrepareDemoJob::class);

        $site->forceFill(['status' => DemoSite::FAILED, 'retries' => RetryDemo::MOST])->save();
        $this->post('/demo/'.$site->token.'/again')->assertSessionHas('demo_error', 'too_many');
    }
}
