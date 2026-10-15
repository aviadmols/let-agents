<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A shopper's search handed to their own AI: a short link to the words, what they were read as,
 * the products with what the store approved of them and the store's policies, as a page an AI
 * reads and as JSON. No model, nothing about the shopper, never indexed.
 */
final class ShareSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_8888888888888888888888888888888888888888888888888';

    private const ORIGIN = ['Origin' => 'https://www.rings.test'];

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['name' => 'טבעות ושות׳', 'content_locale' => 'he']);
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://www.rings.test', 'access_token' => self::TOKEN]));
        $this->site = SiteKeys::site(self::TOKEN);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'ring', 'label' => ['he' => 'טבעת', 'en' => 'Ring']]],
                    'attributes' => [['key' => 'metal', 'type' => 'enum', 'label' => ['he' => 'מתכת', 'en' => 'Metal'], 'values' => [
                        ['key' => 'white_gold', 'label' => ['he' => 'זהב לבן', 'en' => 'White gold']], ['key' => 'yellow_gold', 'label' => ['he' => 'זהב צהוב', 'en' => 'Yellow gold']]]]]]]);

            foreach ([['1', 'טבעת סוליטר', 2400, 'white_gold'], ['2', 'טבעת וינטג׳', 1800, 'yellow_gold'], ['3', 'טבעת יוקרה', 9000, 'white_gold']] as [$id, $title, $price, $metal]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'variable', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.rings.test/p/{$id}", 'image_url' => "https://cdn.rings.test/{$id}.jpg", 'price' => $price, 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
                    'synced_at' => now(), 'payload' => ['description' => '<p>טבעת עבודת יד.</p>', 'variations' => [
                        ['external_id' => "{$id}1", 'title' => 'מידה 6', 'price' => ['price' => (string) $price], 'stock' => ['in_stock' => true]],
                        ['external_id' => "{$id}2", 'title' => 'מידה 7', 'price' => ['price' => (string) $price], 'stock' => ['in_stock' => false]],
                    ]]]);
                foreach ([[FactKind::Type, 'type', 'ring'], [FactKind::Choice, 'metal', $metal]] as $i => [$kind, $key, $value]) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key,
                        'value_text' => $value, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "f{$id}{$i}"]);
                }
            }

            $returns = CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'page', 'external_id' => 'policy-1', 'title' => 'מדיניות החזרות', 'url' => 'https://www.rings.test/policies/refund', 'body' => 'ניתן להחזיר תוך 14 יום.', 'hash' => 'p']);
            EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'content_id' => $returns->id, 'kind' => FactKind::Promise, 'key' => 'returns', 'value_text' => '14 יום',
                'quote' => 'ניתן להחזיר תוך 14 יום.', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'pr']);
        });

        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
    }

    public function test_a_search_is_handed_to_the_shoppers_ai_as_a_page_it_reads_and_nothing_else(): void
    {
        $config = $this->getJson("/api/v1/search/{$this->site}/index", self::ORIGIN)->assertOk()->json('config');
        $this->assertTrue($config['aiShare'], 'the script shows the button for the AI');
        $this->assertTrue($config['shareResults'], 'and the one for a friend');

        $sent = $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://www.rings.test', 'CONTENT_TYPE' => 'text/plain'],
            json_encode(['q' => 'טבעת זהב לבן עד 3000 ש"ח', 'ids' => ['1', '3', 'nope'], 'locale' => 'he']))->assertCreated()->json();

        $this->assertMatchesRegularExpression('#/s/[a-z0-9]{12}$#', $sent['url']);
        $this->assertStringContainsString($sent['url'], $sent['prompt']);
        $this->assertStringContainsString('טבעות ושות׳', $sent['prompt']);
        $this->assertSame('https://chatgpt.com/?q='.rawurlencode($sent['prompt']), $sent['open']['chatgpt']);
        $this->assertStringStartsWith('https://claude.ai/new?q=', $sent['open']['claude']);

        $again = $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://www.rings.test', 'CONTENT_TYPE' => 'text/plain'],
            json_encode(['q' => 'טבעת זהב לבן עד 3000 ש"ח', 'ids' => ['1', '3']]))->json();
        $this->assertSame($sent['url'], $again['url'], 'the same search and products: one link');

        $path = (string) parse_url($sent['url'], PHP_URL_PATH);
        $page = $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page->assertSee('טבעת סוליטר')->assertSee('טבעת יוקרה')->assertDontSee('טבעת וינטג')
            ->assertSee('זהב לבן')->assertSee('₪2,400')->assertSee('עד ₪3,000')->assertSee('מתכת: זהב לבן')
            ->assertSee('מידה 7 – ₪2,400')->assertSee('(אזל מהמלאי)')->assertSee('ניתן להחזיר תוך 14 יום.')->assertSee('https://www.rings.test/policies/refund')
            ->assertSee('application/ld+json', false)->assertSee('"@context":"https://schema.org"', false)->assertSee('"@type":"Product"', false)
            // For the AI: the instructions, then everything as JSON, and a buy link on every option in stock.
            ->assertSee('data-ai-guide', false)->assertSee('data-ai-json', false)->assertSee('סיים כל תשובה שממליצה על מוצר בקישור')
            ->assertSee('href="https://www.rings.test/?add-to-cart=1&amp;variation_id=11"', false)->assertDontSee('variation_id=12', false)
            // Shared with a friend: a preview in the chat, and the way to the same search on the store.
            ->assertSee('<meta property="og:title" content="חיפוש בטבעות ושות׳: &quot;טבעת זהב לבן עד 3000 ש&quot;ח&quot;">', false)
            ->assertSee('<meta property="og:image" content="https://cdn.rings.test/1.jpg">', false)
            ->assertSee('2 מוצרים מטבעות ושות׳')
            ->assertSee('href="https://www.rings.test/?s='.rawurlencode('טבעת זהב לבן עד 3000 ש"ח').'&amp;post_type=product"', false);

        $json = $this->getJson($path.'?format=json')->assertOk()->json();
        $this->assertSame(['טבעת סוליטר', 'טבעת יוקרה'], array_column($json['products'], 'title'), 'the products the shopper saw, in their order');
        $this->assertSame('for_ai', array_key_first($json), 'the instructions come first');
        $this->assertStringContainsString('סיים כל תשובה', implode(' ', $json['for_ai']));
        $this->assertSame('זהב לבן', $json['products'][0]['details']['מתכת']);
        $this->assertSame('https://www.rings.test/?add-to-cart=1&variation_id=11', $json['products'][0]['options'][0]['buy_url']);
        $this->assertSame(3000.0, (float) $json['read']['max_price']);
        $this->assertArrayNotHasKey('vid', $json);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/s/', $sent['url'], 'the platform\'s own address, whatever address the request came on');

        $share = app(TenantContext::class)->runUnscoped(fn () => SearchShare::query()->sole());
        $this->assertSame(2, $share->opens);
    }

    public function test_without_products_the_words_find_them_and_an_expired_link_says_so(): void
    {
        $sent = $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://www.rings.test', 'CONTENT_TYPE' => 'text/plain'],
            json_encode(['q' => 'טבעת וינטג']))->assertCreated()->json();
        $path = (string) parse_url($sent['url'], PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('טבעת וינטג׳');

        app(TenantContext::class)->runUnscoped(fn () => SearchShare::query()->update(['expires_at' => now()->subMinute()]));
        $this->get($path)->assertStatus(410)->assertSee('הקישור הזה פג');
        $this->get('/s/nosuchtoken1')->assertNotFound();
    }

    public function test_off_for_a_shop_or_from_another_site_nothing_is_made(): void
    {
        $body = json_encode(['q' => 'טבעת']);
        $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'CONTENT_TYPE' => 'text/plain'], $body)->assertForbidden();

        // The AI's button off: sharing with a friend still makes links.
        Features::override('search.ai_share', false, $this->shop->id);
        $this->assertFalse($this->getJson("/api/v1/search/{$this->site}/index", self::ORIGIN)->json('config.aiShare'));
        $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://www.rings.test', 'CONTENT_TYPE' => 'text/plain'], $body)->assertCreated();
        app(TenantContext::class)->runUnscoped(fn () => SearchShare::query()->delete());

        // Both off: no link at all.
        Features::override('search.share_results', false, $this->shop->id);
        $this->call('POST', "/api/v1/search/{$this->site}/share", [], [], [], ['HTTP_ORIGIN' => 'https://www.rings.test', 'CONTENT_TYPE' => 'text/plain'], $body)->assertNotFound();
        $this->assertSame(0, app(TenantContext::class)->runUnscoped(fn () => SearchShare::query()->count()));
    }

    public function test_the_whole_store_is_one_page_for_an_ai_instructions_first_every_product_with_its_buy_link(): void
    {
        config(['upsell.shop_domain' => 'agents.example.test']);
        $page = $this->get("/c/{$this->site}?q=".rawurlencode('טבעת זהב לבן'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page->assertSee('כל המוצרים של טבעות ושות׳')->assertSee('data-ai-guide', false)->assertSee('data-ai-json', false)
            ->assertSee('https://agents.example.test/c/'.$this->site.'?q=…')
            ->assertSee('טבעת סוליטר')->assertSee('טבעת יוקרה')->assertDontSee('טבעת וינטג')
            ->assertSee('2 מוצרים ל&quot;טבעת זהב לבן&quot;', false);

        $all = $this->getJson("/c/{$this->site}?format=json")->assertOk()->json();
        $this->assertSame('for_ai', array_key_first($all));
        $this->assertSame(3, $all['total'], 'no words: every product of the store');
        $this->assertSame(1, $all['pages']);
        $this->assertSame('https://agents.example.test/c/'.$this->site, $all['store']['catalog']);
        $this->assertNotEmpty($all['policies']['promises']);

        $this->get('/c/'.str_repeat('0', 24))->assertNotFound();
        Features::override('search.agent_connector', false, $this->shop->id);
        Cache::flush();
        $this->get("/c/{$this->site}")->assertNotFound();
    }

    public function test_the_order_an_ai_put_together_is_one_link_to_the_stores_checkout(): void
    {
        // Every page tells the AI how: the order link is among its commands.
        $actions = $this->getJson("/c/{$this->site}?format=json")->json('actions');
        $this->assertSame(['search', 'all_products', 'order', 'check_order'], array_column($actions, 'name'));
        $this->assertStringContainsString('/c/'.$this->site.'/cart?items=', $actions[2]['url']);

        // WooCommerce, one product with its size: to the store, the product added.
        $this->get("/c/{$this->site}/cart?items=1/11:2")->assertRedirect('https://www.rings.test/?add-to-cart=1&variation_id=11&quantity=2');

        // The size not chosen yet: a page with each size, each a link that completes the order.
        $this->get("/c/{$this->site}/cart?items=1")->assertOk()->assertSee('בחרו אפשרות כדי להמשיך לתשלום')
            ->assertSee('/c/'.$this->site.'/cart?items='.rawurlencode('1/11:1'), false)->assertDontSee(rawurlencode('1/12:1'), false);
        $check = $this->getJson("/c/{$this->site}/cart?items=1&format=json")->assertOk()->json();
        $this->assertFalse($check['ready']);
        $this->assertSame('choose_option', $check['problems'][0]['problem']);

        // Shopify: every item in one cart, straight to checkout.
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->update(['platform' => 'shopify']));
        $this->get("/c/{$this->site}/cart?items=1/11:2,2/21")->assertRedirect('https://www.rings.test/cart/11:2,21:1?attributes[la_source]=ai');
        $this->assertTrue($this->getJson("/c/{$this->site}/cart?items=1/11,2/21&format=json")->json('ready'));
    }

    public function test_the_store_is_a_connector_a_shoppers_ai_searches_reads_and_makes_a_cart_link_with(): void
    {
        $rpc = fn (string $method, array $params = [], ?int $id = 1) => $this->call('POST', "/api/v1/mcp/{$this->site}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params] + ($id === null ? [] : ['id' => $id])));

        $hello = $rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude', 'version' => '1']])->assertOk()->json('result');
        $this->assertSame('2025-06-18', $hello['protocolVersion']);
        $this->assertSame('טבעות ושות׳', $hello['serverInfo']['title']);
        $this->assertStringContainsString('create_cart_link', $hello['instructions']);
        $rpc('notifications/initialized', [], null)->assertStatus(202);

        $this->assertSame(['search_products', 'get_product', 'store_policies', 'create_cart_link'], array_column($rpc('tools/list')->json('result.tools'), 'name'));

        $found = $rpc('tools/call', ['name' => 'search_products', 'arguments' => ['query' => 'טבעת זהב לבן עד 3000 ש"ח']])->assertOk()->json('result');
        $this->assertFalse($found['isError']);
        $this->assertSame(['טבעת סוליטר'], array_column($found['structuredContent']['products'], 'title'), 'white gold, up to 3000: not the yellow one, not the 9000 one');
        $this->assertSame([['name' => 'מתכת', 'value' => 'זהב לבן']], $found['structuredContent']['read']['filters']);
        $this->assertEquals(['id' => '11', 'title' => 'מידה 6', 'price' => 2400, 'in_stock' => true, 'buy_url' => 'https://www.rings.test/?add-to-cart=1&variation_id=11'], $found['structuredContent']['products'][0]['variants'][0]);
        $this->assertStringContainsString('buy_url', $hello['instructions'], 'told to end every answer with the buy link');
        $this->assertJson($found['content'][0]['text']);

        $this->assertSame('טבעת וינטג׳', $rpc('tools/call', ['name' => 'get_product', 'arguments' => ['id' => '2']])->json('result.structuredContent.title'));
        $this->assertTrue($rpc('tools/call', ['name' => 'get_product', 'arguments' => ['id' => 'nope']])->json('result.isError'));
        $this->assertSame('ניתן להחזיר תוך 14 יום.', $rpc('tools/call', ['name' => 'store_policies'])->json('result.structuredContent.policies.promises.0.detail'));

        // A product with sizes needs one chosen; then a link to the store's own cart.
        $cart = fn (array $items) => $rpc('tools/call', ['name' => 'create_cart_link', 'arguments' => ['items' => $items]])->json('result.structuredContent');
        $choose = $cart([['product' => '1']]);
        $this->assertNull($choose['url']);
        $this->assertSame('choose_option', $choose['problems'][0]['problem']);
        $this->assertSame(['11', '12'], array_column($choose['problems'][0]['options'], 'id'));
        $this->assertSame('https://www.rings.test/?add-to-cart=1&variation_id=11&quantity=2', $cart([['product' => '1', 'variant' => '11', 'quantity' => 2]])['url'], 'WooCommerce: an add-to-cart link');

        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->update(['platform' => 'shopify']));
        $this->assertSame('https://www.rings.test/cart/11:2,21:1?attributes[la_source]=ai', $cart([['product' => '1', 'variant' => '11', 'quantity' => 2], ['product' => '2', 'variant' => '21']])['url'], 'Shopify: one cart for both, marked as from an AI');

        // A product without options: its one variant, read live from its public page, when it can be bought.
        app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '4', 'type' => 'simple', 'status' => 'publish',
            'title' => 'שרשרת', 'url' => 'https://www.rings.test/products/chain', 'price' => 500, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h4', 'payload' => []]));
        Http::fake(['www.rings.test/products/chain.js' => Http::response(['variants' => [['id' => 777, 'available' => false], ['id' => 778, 'available' => true]]])]);
        $this->assertSame('https://www.rings.test/cart/778:1?attributes[la_source]=ai', $cart([['product' => '4']])['url']);

        $this->assertSame(-32601, $rpc('resources/list')->json('error.code'));
        $this->get("/api/v1/mcp/{$this->site}")->assertStatus(405);
        Features::override('search.agent_connector', false, $this->shop->id);
        $rpc('tools/list')->assertNotFound();
    }
}
