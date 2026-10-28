<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\Onboarding;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new shop's first-run screens: connect (a Shopify store skips it), what Lets found (only what is real,
 * fixed with Settings · General's own saving), the scan beside a try, and the first steps; once through,
 * the panel opens on the home and never sends the shop here again. A viewer goes through and changes nothing.
 */
final class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> what the fake module counted */
    public static array $facts = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$facts = [];
        OnboardingTestSteps::$live = false;
        $this->app->tag([OnboardingTestFacts::class], ShopFacts::TAG);
        app()->setLocale('he');
    }

    public function test_a_shopify_store_came_connected_and_starts_at_what_lets_found(): void
    {
        $this->as(Shop::factory()->onboarding()->create(['platform' => ShopPlatform::Shopify]));

        Livewire::test(Onboarding::class)
            ->assertSet('step', 2)
            ->assertSee('זיהינו את החנות. נכון?')
            ->assertDontSee('נעים להכיר. איפה החנות שלכם?')
            ->assertSee('Shopify · מחובר')
            ->assertDontSee('data-tab="connect"', false)
            ->assertSee('data-tab="check"', false)
            // no way back to a connection it does not need
            ->set('step', 1)
            ->assertSet('step', 2);

        // A WooCommerce store starts at the connection: its plugin and token.
        $this->as(Shop::factory()->onboarding()->create());

        Livewire::test(Onboarding::class)
            ->assertSet('step', 1)
            ->assertSee('נעים להכיר. איפה החנות שלכם?')
            ->assertSee('data-tab="connect"', false)
            ->assertSee('data-plugin', false)
            ->assertSee('לחבר ולקרוא את החנות');
    }

    public function test_connecting_a_woocommerce_store_asks_the_store_and_starts_reading_it(): void
    {
        $this->as(Shop::factory()->onboarding()->create());
        $plugin = new OnboardingTestPlugin;
        $scan = new OnboardingTestScan;
        $this->app->instance(StorePlugin::class, $plugin);
        $this->app->instance(ShopScan::class, $scan);

        $plugin->answers = false;
        Livewire::test(Onboarding::class)->call('connect')->assertSet('step', 1)->assertNotified('החנות עוד לא ענתה. מתקינים את התוסף, מדביקים בו את הטוקן ולוחצים שוב.');
        $this->assertSame(0, $scan->started);

        $plugin->answers = true;
        Livewire::test(Onboarding::class)->call('connect')->assertSet('step', 2);
        $this->assertSame(1, $scan->started, 'connecting reads the store: the scan starts');
    }

    public function test_what_lets_found_is_what_the_store_says_and_nothing_made_up(): void
    {
        $shop = Shop::factory()->onboarding()->create(['name' => 'גואטה אביגדור', 'platform' => ShopPlatform::WooCommerce, 'content_locale' => 'he']);
        $this->as($shop);

        // nothing said and nothing counted yet: the name, the platform and the language, no more
        Livewire::test(Onboarding::class)->set('step', 2)
            ->assertSee('data-found-row="name"', false)
            ->assertSee('גואטה אביגדור')
            ->assertSee('עברית (עיקרית)')
            ->assertDontSee('data-found-row="site"', false)
            ->assertDontSee('data-found-row="sells"', false)
            ->assertDontSee('data-found-row="seen"', false)
            ->assertDontSee('מוצרים');

        $shop->forceFill(['profile' => ['kind' => 'both', 'languages' => ['en'], 'sells' => ['חומרי בניין ועץ', 'כלי עבודה']]])->save();
        self::$facts = ['products' => 2160, 'pages' => 37, 'seen' => ['text' => 'משלוח עד 5 ימי עסקים, חינם מעל 500', 'pages' => 9]];

        Livewire::test(Onboarding::class)->set('step', 2)
            ->assertSee('WooCommerce · 2,160 מוצרים · 37 דפים ופוסטים')
            ->assertSee('עברית (עיקרית) · English')
            ->assertSee('חנות עם בלוג')
            ->assertSee('חומרי בניין ועץ · כלי עבודה')
            ->assertSee('משלוח עד 5 ימי עסקים, חינם מעל 500 · ב-9 עמודי מוצר')
            ->assertSeeHtmlInOrder(['data-found-row="name"', 'data-found-row="store"', 'data-found-row="languages"', 'data-found-row="site"', 'data-found-row="sells"', 'data-found-row="seen"']);
    }

    public function test_fixing_what_was_found_saves_it_as_settings_general_does_and_starts_the_scan(): void
    {
        $shop = Shop::factory()->onboarding()->create(['name' => 'חנות', 'content_locale' => 'he']);
        $this->as($shop);
        $scan = new OnboardingTestScan;
        $this->app->instance(ShopScan::class, $scan);

        $page = Livewire::test(Onboarding::class)->set('step', 2)
            ->call('fix')
            ->assertSee('data-form', false)
            ->assertSee('שמירה, להתחיל לסרוק')
            ->set('data.name', 'א')
            ->call('saveProfile')
            ->assertSee('שם החנות קצר מדי.')
            ->assertSet('step', 2);
        $this->assertSame('חנות', $shop->fresh()?->name, 'nothing saved while something is wrong');
        $this->assertSame(0, $scan->started);

        $page->set('data.name', 'ספרים ובגדים')
            ->set('data.languages', ['en', 'he', 'xx'])
            ->set('data.kind', 'content')
            ->set('data.sells', [' ספרים ', 'בגדים', ''])
            ->call('saveProfile')
            ->assertNotified('נשמר')
            ->assertSet('step', 3)
            ->assertSet('fixing', false);

        $shop = $shop->fresh();
        $this->assertSame('ספרים ובגדים', $shop?->name);
        $this->assertSame('en', $shop?->content_locale, 'the first language is the main one');
        // Postgres keeps a jsonb object's keys in its own order (SQLite as written): the keys in any order, each list in its own
        $profile = (array) $shop?->profile;
        ksort($profile);
        $this->assertSame(['kind' => 'content', 'languages' => ['he'], 'sells' => ['ספרים', 'בגדים']], $profile);
        $this->assertSame(1, $scan->started);
    }

    public function test_finishing_sets_the_shop_up_and_its_panel_opens_on_the_home_from_then_on(): void
    {
        $shop = Shop::factory()->onboarding()->create();
        $owner = $this->as($shop);

        // a new shop's home sends its manager here, with the address it came with
        $this->get("/merchant/{$shop->slug}/overview?embedded=1&host=abc")->assertRedirect(Onboarding::getUrl().'?embedded=1&host=abc');

        Livewire::test(Onboarding::class)
            ->set('step', 3)
            ->assertSee('Lets מכיר את החנות')
            ->assertSee('data-scan-row="products"', false)
            ->call('toFirstSteps')
            ->assertSet('step', 4)
            ->assertSee('לדף הבית')
            ->call('finish')
            ->assertRedirect(ShopOverview::getUrl());

        $this->assertNotNull($shop->fresh()?->onboarded_at);
        $this->actingAs($owner)->get("/merchant/{$shop->slug}/overview")->assertOk()->assertSee('data-home', false);
    }

    public function test_on_shopify_the_try_asks_for_lets_to_be_on_in_the_theme_first(): void
    {
        $this->app->tag([OnboardingTestSteps::class], FirstSteps::STEPS_TAG);
        $this->as(Shop::factory()->onboarding()->create(['platform' => ShopPlatform::Shopify]));

        // the search is in a Shopify store only once the app embed is on: that comes before the try
        Livewire::test(Onboarding::class)->set('step', 3)
            ->assertSee('data-try', false)
            ->assertSee('להפעיל בתבנית')
            ->assertSee(OnboardingTestSteps::EMBED, false)
            ->assertDontSee('בתצוגה מקדימה שרק אתם רואים')
            ->assertSeeHtmlInOrder(['data-embed-link', 'lp-onb-try-field']);

        // once Lets is live there is nothing to switch on
        OnboardingTestSteps::$live = true;
        Livewire::test(Onboarding::class)->set('step', 3)->assertSee('data-try', false)->assertDontSee('data-embed-link', false);

        // a WooCommerce store has no app embed: its try is as it was
        OnboardingTestSteps::$live = false;
        $this->as(Shop::factory()->onboarding()->create());
        Livewire::test(Onboarding::class)->set('step', 3)->assertSee('data-try', false)->assertDontSee('data-embed-link', false);
    }

    public function test_a_viewer_is_not_sent_here_and_changes_nothing_on_it(): void
    {
        $shop = Shop::factory()->onboarding()->create(['name' => 'חנות']);
        $this->as($shop, ShopRole::Viewer);

        $this->get("/merchant/{$shop->slug}/overview")->assertOk();

        Livewire::test(Onboarding::class)
            ->assertSee('יש לכם הרשאת צפייה')
            ->assertDontSee('wire:click="connect"', false)
            ->set('step', 2)
            ->assertSet('step', 2)
            ->assertDontSee('data-fix', false)
            ->set('data.name', 'שם אחר')
            ->call('saveProfile')
            ->call('finish');

        $this->assertSame('חנות', $shop->fresh()?->name);
        $this->assertNull($shop->fresh()?->onboarded_at);
    }

    private function as(Shop $shop, ShopRole $role = ShopRole::Owner): User
    {
        $user = User::factory()->create(['locale' => 'he']);
        $user->attachShop($shop, $role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        return $user;
    }
}

/** A module's count, as the test sets it. */
final class OnboardingTestFacts implements ShopFacts
{
    public function shopFacts(Shop $shop): array
    {
        return OnboardingTest::$facts;
    }
}

final class OnboardingTestPlugin implements StorePlugin
{
    public ?bool $answers = null;

    public function package(): ?array
    {
        return null;
    }

    public function issueToken(Shop $shop): string
    {
        return 'token';
    }

    public function check(Shop $shop): ?bool
    {
        return $this->answers;
    }
}

final class OnboardingTestScan implements ShopScan
{
    public int $started = 0;

    public function progress(Shop $shop): ?array
    {
        return null;
    }

    public function start(Shop $shop): bool
    {
        $this->started++;

        return true;
    }

    public function learned(Shop $shop): array
    {
        return [];
    }
}

/** The search's first steps as the test sets them: on Shopify "on" opens the theme editor; "look" opens the store. */
final class OnboardingTestSteps implements FirstSteps
{
    public const EMBED = 'https://gueta-test.myshopify.com/admin/themes/current/editor?context=apps';

    public static bool $live = false;

    public function steps(Shop $shop): array
    {
        $shopify = $shop->platform === ShopPlatform::Shopify;

        return [
            new FirstStep(key: 'on', order: 1, title: 'on', body: 'on', button: 'on', url: $shopify ? self::EMBED : '/settings', done: self::$live, external: $shopify),
            new FirstStep(key: 'look', order: 2, title: 'look', body: 'look', button: 'look', url: 'https://shop.test/?let_agents_preview=abc', done: self::$live, external: true),
        ];
    }
}
