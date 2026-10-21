<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Filament\Merchant\Pages\LookSettings;
use App\Modules\Search\Filament\Merchant\Pages\ManualConnectSettings;
use App\Modules\Search\Filament\Merchant\Pages\SearchDisplay;
use App\Modules\Search\Filament\Merchant\Pages\SearchWindowSettings;
use App\Modules\Search\Filament\Merchant\Pages\ShopSearches;
use App\Modules\Search\Models\SearchAnswerRating;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\SearchHome;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The search's settings pages as the shop's team works them (how it looks, the search window, the manual
 * connection): each shows the designer's words, saves the shop's own values only, and the storefront is
 * told: the drawer's look endpoint and the index config. And "Helpful?": a thumb down reaches the notices.
 */
final class SearchSettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_7777777777777777777777777777777777777777777777777';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['name' => 'גואטה']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.gueta.test', 'access_token' => self::TOKEN,
        ]));
    }

    public function test_how_it_looks_saves_the_look_the_language_and_the_assistants_name(): void
    {
        $page = Livewire::test(LookSettings::class)
            ->assertOk()
            ->assertSee('איך זה נראה')
            ->assertSee('הרכיב נראה אותו דבר בחיפוש, בעמוד המוצר וברשימת המשאלות. מה שבוחרים כאן חל על כולם.')
            ->assertSee('שחור על לבן, Google Sans. נראה אותו דבר בכל חנות.')
            ->assertSee('בתוך מסגרת (frame)')
            ->assertSee('כמו האתר')
            ->assertSee('הגולשים יראו את השינוי בתוך כמה דקות')
            ->assertSet('data.theme', 'lets')
            ->assertSet('data.images', 'cover')
            ->assertSet('data.language', 'site')
            ->assertSet('data.assistant', 'גואטה AI');

        $look = $this->look();
        $this->assertSame(['lets', null, 'גואטה AI'], [$look['theme'], $look['lang'], $look['assistant']]);

        $page->set('data.theme', 'store')->set('data.images', 'frame')->set('data.language', 'en')->set('data.assistant', 'העוזר של גואטה')->call('save');

        $this->assertSame('store', Settings::get('search.theme', $this->shop->id));
        $this->assertSame('frame', Settings::get('search.image_fit', $this->shop->id));
        $this->assertSame('en', Settings::get('search.widget_language', $this->shop->id));
        $this->assertSame('העוזר של גואטה', Settings::get('search.assistant_name', $this->shop->id));

        $look = $this->look();
        $this->assertSame(['store', 'en', 'העוזר של גואטה'], [$look['theme'], $look['lang'], $look['assistant']]);

        // The default name back: nothing of the shop's own, so a renamed shop's assistant follows the shop.
        $page->set('data.assistant', 'גואטה AI')->set('data.language', 'site')->call('save');
        $this->assertNull(Settings::overrideFor('search.assistant_name', $this->shop->id));
        $this->assertNull(Settings::overrideFor('search.widget_language', $this->shop->id));
        $this->shop->forceFill(['name' => 'גואטה אביגדור'])->save();
        $this->assertSame('גואטה אביגדור AI', $this->look()['assistant']);

        $page->set('data.assistant', str_repeat('א', 61))->call('save')->assertSee('עד 60 תווים');
    }

    public function test_the_search_window_switches_what_the_drawer_offers(): void
    {
        $this->product('1', 'טבעת סוליטר');
        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($this->shop->id)->status->value);

        $page = Livewire::test(SearchWindowSettings::class)
            ->assertOk()
            ->assertSee('חלון החיפוש')
            ->assertSee('מה שקורה כשגולש לוחץ על תיבת החיפוש של האתר. כל מתג כאן נראה מיד לגולשים.')
            ->assertSee('החיפוש החכם בתיבת החיפוש של האתר')
            ->assertDontSee('התמונות עוד נסרקות')
            ->assertSee('לבקש דירוג לתשובה')
            ->assertSee('"עזר?" אחרי תשובה של העוזר')
            ->assertSee('כשמוצר או מידה אזלו')
            ->assertSee('שורת Lets בעמוד המוצר שמציעה מוצרים דומים במלאי, באותה מידה')
            ->assertSee('הזמנה לנסות את החיפוש')
            ->assertSet('data.storefront', true)
            ->assertSet('data.take_over', true)
            ->assertSet('data.nudge', true)
            ->assertSet('data.rate', true)
            ->assertSet('data.out_of_stock', true)
            ->assertSet('data.per_group', 12);

        $config = $this->config();
        $this->assertTrue($config['takeOver']);
        $this->assertTrue($config['rate']);
        $this->assertTrue($config['nudge']);
        $this->assertTrue($this->look()['nudge'], 'the invitation is read from the look, before the index is needed');
        $this->assertSame('', $this->look()['nudgeAt'], 'by default the drawer finds the icon itself');
        // what a question with no answer is offered, and the shop's own form for it (none set up here)
        $this->assertContains($config['noAnswer'], ['team', 'details', 'none']);
        $this->assertArrayHasKey('leadForm', $config);
        Features::override('shoppers.callbacks', false, $this->shop->id);
        $this->assertSame('none', $this->config()['noAnswer'], 'no details taken and no WhatsApp: only "not found"');
        $this->assertNull($this->config()['leadForm']);
        Features::clearOverride('shoppers.callbacks', $this->shop->id);

        $page->set('data.take_over', false)
            ->set('data.photos', true)
            ->set('data.price', false)
            ->set('data.per_group', 8)
            ->set('data.wishlist', true)
            ->set('data.rate', false)
            ->set('data.out_of_stock', false)
            ->set('data.nudge', false)
            ->set('data.nudge_at', ' header__search-link  icon ')
            ->call('save');

        // the posts Lets wrote: out of the results by default; turned on, saved, and the search index built again at once
        $this->assertFalse(Features::enabled('search.lets_posts', $this->shop->id));
        $built = Run::query()->where('agent', BuildSearchIndex::AGENT)->count();
        $page->assertSee('פוסטים של Lets בתוצאות החיפוש')->set('data.lets_posts', true)->call('save');
        $this->assertTrue(Features::enabled('search.lets_posts', $this->shop->id));
        $this->assertSame($built + 1, Run::query()->where('agent', BuildSearchIndex::AGENT)->count(), 'the index is built again when the posts go in or out');
        $page->call('save');
        $this->assertSame($built + 1, Run::query()->where('agent', BuildSearchIndex::AGENT)->count(), 'not on a save that did not change them');

        $this->assertFalse(Features::enabled('search.take_over_field', $this->shop->id));
        $this->assertFalse(Features::enabled('search.nudge', $this->shop->id));
        $this->assertTrue(Features::enabled('search.photos', $this->shop->id));
        $this->assertTrue(Features::enabled('retrieval.image_index', $this->shop->id), 'photo search on starts the pictures scan');
        $page->assertSee('התמונות עוד נסרקות');
        $this->assertFalse(Settings::get('search.show_price', $this->shop->id));
        $this->assertSame(8, Settings::get('search.results_per_group', $this->shop->id));
        $this->assertTrue(Features::enabled('shortlist.wishlist', $this->shop->id));
        $this->assertFalse(Features::enabled('search.rate_answers', $this->shop->id));
        $this->assertFalse(Features::enabled('search.out_of_stock_similar', $this->shop->id));
        // only what differs from what the shop inherits is the shop's own
        $this->assertNull(Features::overrideFor('search.similar', $this->shop->id));

        $config = $this->config();
        $this->assertFalse($config['takeOver']);
        $this->assertFalse($config['rate']);
        $this->assertFalse($config['oosSimilar']);
        $this->assertFalse($config['price']);
        $this->assertFalse($this->look()['nudge']);
        // the class the team copied from the inspector, whole class attribute and all, is a selector for the drawer
        $this->assertSame('.header__search-link.icon', $this->look()['nudgeAt']);
        Settings::set('search.nudge_at', 'header .search-toggle > svg', $this->shop->id);
        $this->assertSame('header .search-toggle > svg', $this->look()['nudgeAt'], 'a selector stays as written');

        // the card's own switch: the search off on the site
        $page->set('data.storefront', false)->call('save');
        $this->assertFalse($this->look()['enabled']);
    }

    public function test_the_manual_connection_keeps_the_shops_own_selector(): void
    {
        StoreConnection::query()->withoutGlobalScopes()->update(['site_info' => json_encode(['plugin' => ['version' => '0.10.0']]), 'status' => 'connected']);

        $page = Livewire::test(ManualConnectSettings::class)
            ->assertOk()
            ->assertSee('החיפוש לא מופיע באתר?')
            ->assertSee('עוד לא ראינו חיפושים מהאתר')
            ->assertSee('WooCommerce: התוסף פעיל והמפתח מחובר')
            ->assertSee('Let Agents 0.10.0')
            ->assertSee('<input data-lets-search placeholder="חיפוש באתר">')
            ->assertSee('<a data-lets-open>חיפוש</a>')
            ->assertSet('data.selector', '');

        $page->set('data.selector', '  input.header__search-input,   #search-field ')->call('save');
        $this->assertSame('input.header__search-input, #search-field', Settings::get('search.input_selector', $this->shop->id));

        $page->set('data.selector', '')->call('save');
        $this->assertNull(Settings::overrideFor('search.input_selector', $this->shop->id));
        $this->assertSame('input[name="s"], input[type="search"]', Settings::get('search.input_selector', $this->shop->id));
    }

    public function test_the_old_search_pages_leave_the_settings_menu_and_still_open(): void
    {
        $this->assertFalse(SearchDisplay::shouldRegisterNavigation());
        $this->assertFalse(ShopSearches::shouldRegisterNavigation());
        $this->assertTrue(SearchWindowSettings::shouldRegisterNavigation());

        $this->get("/merchant/{$this->shop->slug}/search/display")->assertOk();
        $this->get("/merchant/{$this->shop->slug}/search/terms")->assertOk();
        // the settings side menu leads to the new pages, never to the old ones
        $this->get("/merchant/{$this->shop->slug}/settings/look")->assertOk()
            ->assertSee("/merchant/{$this->shop->slug}/settings/search", false)
            ->assertSee("/merchant/{$this->shop->slug}/settings/manual", false)
            ->assertDontSee("/merchant/{$this->shop->slug}/search/display", false)
            ->assertDontSee("/merchant/{$this->shop->slug}/search/terms", false);
        $this->get("/merchant/{$this->shop->slug}/settings/search")->assertOk();
        $this->get("/merchant/{$this->shop->slug}/settings/manual")->assertOk();
    }

    public function test_an_answer_rated_down_reaches_the_notices(): void
    {
        $ask = app(TenantContext::class)->run($this->shop->id, fn () => AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => 'יש משלוח לאילת?', 'products' => [],
            'outcome' => 'answered', 'from' => 'model', 'answer' => 'כן, עד 5 ימי עסקים.',
        ]));

        $this->events([['type' => 'ask_rate', 'q' => 'יש משלוח לאילת', 'ask_id' => $ask->id, 'rating' => 'up']]);
        $this->events([['type' => 'ask_rate', 'q' => 'יש משלוח לאילת', 'ask_id' => $ask->id, 'rating' => 'down']]);
        $this->events([['type' => 'ask_rate', 'q' => 'x', 'ask_id' => $ask->id, 'rating' => 'meh']]);

        $ratings = app(TenantContext::class)->run($this->shop->id, fn () => SearchAnswerRating::query()->get());
        $this->assertCount(1, $ratings, 'the last press counts, once per question');
        $this->assertFalse($ratings->first()->helpful);

        $notices = app(TenantContext::class)->run($this->shop->id, fn () => app(SearchHome::class)->notices($this->shop));
        $notice = collect($notices)->first(fn (ShopNotice $n): bool => $n->key === 'search.unhelpful.'.$ask->id);
        $this->assertNotNull($notice);
        $this->assertSame('גולש סימן שהתשובה לא עזרה: "יש משלוח לאילת?"', $notice->title);
        $this->assertSame('מה העוזר ענה: כן, עד 5 ימי עסקים.', $notice->body);
        $this->assertStringContainsString('/assistant/questions', (string) $notice->url);

        // the shop stopped asking: a thumb is not kept
        Features::override('search.rate_answers', false, $this->shop->id);
        $other = app(TenantContext::class)->run($this->shop->id, fn () => AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => 'אחריות?', 'products' => [], 'outcome' => 'answered', 'from' => 'model',
        ]));
        $this->events([['type' => 'ask_rate', 'q' => 'אחריות', 'ask_id' => $other->id, 'rating' => 'down']]);
        $this->assertSame(1, app(TenantContext::class)->run($this->shop->id, fn () => SearchAnswerRating::query()->count()));
    }

    public function test_the_sold_out_row_asks_for_similar_items_on_its_own_switch(): void
    {
        Features::override('search.similar', false, $this->shop->id);
        $site = '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id=1';

        $this->get($site)->assertNotFound();
        $this->get($site.'&for=oos')->assertOk();

        Features::override('search.out_of_stock_similar', false, $this->shop->id);
        $this->get($site.'&for=oos')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function look(): array
    {
        return $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/look', ['Origin' => 'https://www.gueta.test'])->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.gueta.test'])->assertOk()->json('config');
    }

    private function events(array $events): void
    {
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/events', server: [
            'HTTP_ORIGIN' => 'https://www.gueta.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['events' => $events], JSON_UNESCAPED_UNICODE))->assertNoContent();
    }

    private function product(string $externalId, string $title): CatalogProduct
    {
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);

        return app(TenantContext::class)->run($this->shop->id, fn (): CatalogProduct => CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish',
            'title' => $title, 'price' => '499.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true,
            'hash' => md5($title), 'payload' => ['title' => $title],
        ]));
    }
}
