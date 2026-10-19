<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Merchant\Pages\AssistantSettings;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\NoAnswer;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · The assistant: what it did in 30 days as the questions screen counts it, where shoppers may
 * ask (the master switch turns all of it off), its daily limits with last month's busiest day, and what
 * a shopper sees when the site has no answer, which every reply that is not an answer carries.
 */
final class AssistantSettingsTest extends TestCase
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
    }

    public function test_it_shows_what_the_assistant_did_and_last_months_busiest_day(): void
    {
        $this->ask('אפשר להחזיר?', 'answered');
        $this->ask('יש עגילי זהב לבן?', 'no_info');
        $this->ask('מה מזג האוויר?', 'out_of_scope');
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
        foreach (['model', 'model', 'none', 'bank', 'bank'] as $from) {
            $this->ask('שאלה', 'answered', $lastMonth, $from);
        }
        $this->ask('שאלה', 'answered', $lastMonth->copy()->addDay());

        Livewire::test(AssistantSettings::class)
            ->assertOk()
            ->assertSee('העוזר')
            ->assertSee('העוזר עונה לגולשים מתוך דפי האתר ומרכז הידע, ומצטט מאיפה התשובה.')
            ->assertSee('פועל')
            ->assertSee('3 שאלות ב-30 יום · 1 נענו מהידע · 1 מחכות לכם')
            ->assertSee('איפה הגולשים יכולים לשאול')
            ->assertSee('בחלון החיפוש')
            ->assertSee('גבולות, כדי שהמכסה החודשית תספיק')
            ->assertSee('בחודש שעבר: עד 3 ביום')
            ->assertSee('הצוות + וואטסאפ')
            ->assertSee('רק "לא מצאנו"')
            ->assertSee('שמירת השינויים');
    }

    public function test_saving_writes_the_shops_own_limits_and_choice(): void
    {
        Livewire::test(AssistantSettings::class)
            ->set('data.per_visitor', 20)
            ->set('data.per_shop', 400)
            ->set('data.no_answer', 'details')
            ->set('data.on_content', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(20, Settings::overrideFor('assistant.questions_per_visitor_per_day', $this->shop->id));
        $this->assertSame(400, Settings::overrideFor('assistant.questions_per_shop_per_day', $this->shop->id));
        $this->assertSame('details', Settings::get('assistant.no_answer', $this->shop->id));
        $this->assertFalse(Features::enabled('assistant.on_content', $this->shop->id));
        $this->assertTrue(Features::enabled('assistant.on_products', $this->shop->id));
        $this->assertNull(Settings::overrideFor('assistant.no_answer', Shop::factory()->create()->id), 'another shop keeps the default');
    }

    public function test_the_master_switch_turns_every_place_off_and_back_on(): void
    {
        Livewire::test(AssistantSettings::class)->set('data.on', false)->call('save')->assertSee('כבוי');

        foreach (['on_products', 'on_content', 'on_search'] as $place) {
            $this->assertFalse(Features::enabled("assistant.{$place}", $this->shop->id), $place);
        }

        Livewire::test(AssistantSettings::class)->assertSet('data.on', false)->set('data.on', true)->call('save')->assertSee('פועל');

        foreach (['on_products', 'on_content', 'on_search'] as $place) {
            $this->assertTrue(Features::enabled("assistant.{$place}", $this->shop->id), $place);
        }
    }

    public function test_what_a_shopper_is_offered_follows_the_choice_and_what_the_shop_has(): void
    {
        $id = $this->shop->id;

        // The team needs WhatsApp on with the shop's own number; until then it is leaving details.
        $this->assertSame(NoAnswer::DETAILS, NoAnswer::offer($id));
        Features::override('widget.whatsapp', true, $id);
        Settings::set('widget.whatsapp_number', '972501234567', $id);
        $this->assertSame(NoAnswer::TEAM, NoAnswer::offer($id));

        Settings::set('assistant.no_answer', 'details', $id);
        $this->assertSame(NoAnswer::DETAILS, NoAnswer::offer($id));

        // Leaving details off: there is nothing to offer but "not found".
        Features::override('shoppers.callbacks', false, $id);
        $this->assertSame(NoAnswer::NONE, NoAnswer::offer($id));

        Features::override('shoppers.callbacks', true, $id);
        Settings::set('assistant.no_answer', 'none', $id);
        $this->assertSame(NoAnswer::NONE, NoAnswer::offer($id));
    }

    private function ask(string $question, string $outcome, $at = null, string $from = 'model'): void
    {
        $at ??= now();
        AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => $at->toDateString(), 'question' => $question, 'products' => [],
            'outcome' => $outcome, 'from' => $from, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
