<?php

namespace App\Modules\Connections\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ConnectionSettings;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · Connection and plan, for a WordPress store: the state of the connection in the manager's
 * words, the plugin zip from their own panel, and a connection token made here, shown once, that the
 * connection then expects (the plugin keeps its hash; the site key follows from it on both sides).
 */
final class MerchantConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'let-agents-merchant-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        config(['upsell.plugin.path' => $this->directory]);

        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold', 'domain' => 'rocks.example.com']);
        $this->owner = User::factory()->create();
        $this->owner->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($this->owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_store_without_a_connection_shows_it_and_offers_the_plugin_and_a_token(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.13.0.zip', 'PK');

        Livewire::test(ConnectionSettings::class)
            ->assertSee('חיבור ומנוי')
            ->assertSee('לא מחובר')
            ->assertSee('WooCommerce · התוסף עוד לא ענה')
            ->assertSee('rocks.example.com')
            ->assertSee('עוד לא נקרא')
            ->assertSee('Lets Pro')
            ->assertSee('ניצול AI החודש')
            ->assertSee('להוריד את התוסף')
            ->assertSee('גרסה 0.13.0')
            ->assertSee('ליצור טוקן חיבור')
            ->assertDontSee('הטוקן החדש. מעתיקים אותו עכשיו');
    }

    public function test_the_plugin_zip_downloads_from_the_merchant_panel(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.12.0.zip', 'PK-old');
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.13.0.zip', 'PK-new');

        Livewire::test(ConnectionSettings::class)
            ->call('downloadPlugin')
            ->assertFileDownloaded('let-agents-0.13.0.zip');
    }

    public function test_a_token_is_made_once_and_the_connection_expects_it(): void
    {
        $page = Livewire::test(ConnectionSettings::class)->call('createToken');
        $token = (string) $page->get('token');

        $this->assertMatchesRegularExpression('/^lat_[A-Za-z0-9]{48}$/', $token, 'the shape the plugin accepts');
        $page->assertSee($token)->assertSee('להעתיק')->assertSee('WooCommerce ← Let Agents ← הגדרות ← להדביק את הטוקן ← לשמור.');

        $connection = StoreConnection::query()->firstOrFail();
        $this->assertSame($token, $connection->access_token);
        $this->assertSame('https://rocks.example.com', $connection->site_url);
        $this->assertSame(SiteKeys::site($token), $connection->site_key, 'the plugin derives the same site key from the token\'s hash');
        $this->assertSame(ConnectionStatus::Untested, $connection->status);

        // A new token replaces it; the page warns before that.
        $again = Livewire::test(ConnectionSettings::class)->assertSee('כבר יש טוקן')->call('createToken');
        $this->assertNotSame($token, $again->get('token'));
        $this->assertSame(1, StoreConnection::query()->count());
        $this->assertSame($again->get('token'), StoreConnection::query()->firstOrFail()->access_token);

        // The token is not on the page once it is opened again.
        Livewire::test(ConnectionSettings::class)->assertDontSee((string) $again->get('token'));
    }

    public function test_only_an_owner_makes_a_token(): void
    {
        $member = User::factory()->create();
        $member->attachShop($this->shop, ShopRole::Member);
        $this->actingAs($member);

        Livewire::test(ConnectionSettings::class)
            ->assertSee('רק בעלי החשבון יכולים לעשות את זה')
            ->call('createToken')
            ->assertSet('token', null);

        $this->assertSame(0, StoreConnection::query()->count());
    }

    public function test_a_connected_store_shows_its_plugin_version_and_the_check_asks_the_store(): void
    {
        $connection = StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://rocks.example.com', 'access_token' => 'lat_'.str_repeat('a', 48)]);
        $connection->forceFill(['status' => ConnectionStatus::Connected, 'site_info' => ['plugin' => ['version' => '0.13.0']]])->save();

        Livewire::test(ConnectionSettings::class)
            ->assertSee('מחובר')
            ->assertDontSee('לא מחובר')
            ->assertSee('WooCommerce · תוסף Let Agents 0.13.0');

        Http::fake(['rocks.example.com/*' => Http::response(['data' => ['plugin' => ['version' => '0.13.0'], 'counts' => ['products' => ['publish' => 3]]]])]);

        Livewire::test(ConnectionSettings::class)->call('checkConnection')->assertNotified('מחובר. Lets קורא את החנות.');
        Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://rocks.example.com/'));
    }
}
