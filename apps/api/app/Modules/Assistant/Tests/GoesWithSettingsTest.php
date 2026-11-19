<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Merchant\Pages\GoesWithSettings;
use App\Modules\Assistant\Models\AssistantGoesWithRules;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · Products that go together: the merchant turns the mechanism on or off, chooses its sources, how many products
 * it shows, which of the store's kinds may be paired, and what is never offered. In plain words: no model, no cost.
 */
final class GoesWithSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'ring', 'label' => ['he' => 'טבעת', 'en' => 'Ring']], ['key' => 'earrings', 'label' => ['he' => 'עגילים', 'en' => 'Earrings']], ['key' => 'socks', 'label' => ['he' => 'גרביים', 'en' => 'Socks']]],
                    'attributes' => []]]);
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת', 'brand' => 'Pandora',
                'url' => 'https://store.test/p/1', 'price' => 500, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
        });
    }

    public function test_the_page_speaks_to_a_merchant_and_names_the_stores_own_kinds(): void
    {
        Livewire::test(GoesWithSettings::class)
            ->assertOk()
            ->assertSee('מוצרים שהולכים יחד')
            ->assertSee('ממה העוזר בוחר')
            ->assertSee('המוצרים שאתם מציעים יחד')
            ->assertSee('אילו סוגים מותר להציע יחד')
            ->assertSee('לא להציע לעולם')
            ->call('addPair')
            ->assertSee('טבעת')
            ->assertSee('גרביים')
            ->assertDontSee('gpt')
            ->assertDontSee('claude')
            ->assertDontSee('usd')
            ->assertDontSee('מודל');
    }

    public function test_saving_writes_the_shops_switch_sources_caps_pairs_and_never_list(): void
    {
        Livewire::test(GoesWithSettings::class)
            ->set('data.on', false)
            ->set('data.use_look', false)
            ->set('data.products', 5)
            ->set('data.max_picks', 2)
            ->call('addPair')
            ->set('data.pairs.0.a', 'ring')
            ->set('data.pairs.0.b', 'socks')
            ->set('data.pairs.0.allow', 0)
            ->call('addPair')
            ->set('data.pairs.1.a', 'ring')
            ->set('data.pairs.1.b', 'earrings')
            ->set('data.pairs.1.allow', 1)
            ->call('addNever')
            ->set('data.never.0.scope', 'vendor')
            ->set('data.never.0.value', 'Pandora')
            ->call('addNever')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Features::enabled('assistant.goes_with', $this->shop->id));
        $this->assertFalse((bool) Settings::get('assistant.goes_with_use_look', $this->shop->id));
        $this->assertTrue((bool) Settings::get('assistant.goes_with_use_relations', $this->shop->id));
        $this->assertSame(5, Settings::get('assistant.goes_with_products', $this->shop->id));
        $this->assertSame(2, Settings::get('assistant.goes_with_max_picks', $this->shop->id));
        $rules = app(TenantContext::class)->run($this->shop->id, fn (): array => AssistantGoesWithRules::of($this->shop->id));
        $this->assertSame([['a' => 'ring', 'b' => 'socks', 'allow' => false], ['a' => 'ring', 'b' => 'earrings', 'allow' => true]], $rules['pairs']);
        $this->assertSame([['scope' => 'vendor', 'value' => 'Pandora']], $rules['never'], 'an empty line is not kept');
        $this->assertFalse(Features::enabled('assistant.goes_with', Shop::factory()->create()->id), 'another shop keeps the default: off until the store turns it on here');
    }

    public function test_a_pair_of_one_kind_or_of_a_kind_the_store_has_not_saves_nothing(): void
    {
        Livewire::test(GoesWithSettings::class)
            ->call('addPair')
            ->set('data.pairs.0.a', 'ring')
            ->set('data.pairs.0.b', 'ring')
            ->call('save');

        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn (): int => AssistantGoesWithRules::query()->count()));
    }
}
