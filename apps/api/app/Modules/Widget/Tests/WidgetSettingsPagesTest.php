<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Filament\Merchant\Pages\OpeningHours;
use App\Modules\Widget\Filament\Merchant\Pages\ProductPage;
use App\Modules\Widget\Filament\Merchant\Pages\ProductWidgetSettings;
use App\Modules\Widget\Filament\Merchant\Pages\WhatsappSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The widget's settings pages as the designer's panel lays them out: the product page block, WhatsApp
 * and the answering hours. Each saves the shop's own word only, and the storefront's cached pages
 * show it on the next request.
 */
final class WidgetSettingsPagesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_settingspages000000000000000000000000000000000';

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        $this->product('10', 'מסור אנכי', 'x', []);

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_the_product_block_page_saves_its_places_parts_and_forgets_the_cached_pages(): void
    {
        $this->assertTrue($this->page('product', '10')->json('enabled'), 'cached now');

        Livewire::test(ProductWidgetSettings::class)
            ->assertSee('הרכיב בעמוד המוצר')
            ->assertSee('הבלוק מתחת לכפתור ההוספה לסל: מפרט, שאלות, מוצרים דומים ומשלימים. נבנה לכל מוצר בלילה.')
            ->assertSee('בעמודי תוכן ומאמרים')
            ->assertSee('מוצרים לכל היותר בכל שורה')
            ->assertSee('על מוצר שנצפה הרבה ב-30 יום')
            ->assertSee('מה מופיע ברכיב')
            ->assertSee('מוצרים מהמאמר')
            ->assertSee('עריכה לפי עמוד')
            ->assertSee(ProductPage::getUrl())
            ->assertSet('data.on', true)
            ->assertSet('data.max_products', 4)
            ->set('data.on_content', false)
            ->set('data.max_products', 6)
            ->set('data.popularity', false)
            ->set('data.parts', ['specs', 'highlights', 'guides'])
            ->call('save')
            ->assertHasNoErrors();

        $shop = $this->shop->id;
        $this->assertFalse(Features::overrideFor('widget.on_content', $shop));
        $this->assertNull(Features::overrideFor('widget.on_products', $shop), 'unchanged: still the default');
        $this->assertSame(6, Settings::get('widget.max_products', $shop));
        $this->assertFalse(Features::enabled('widget.popularity', $shop));
        $this->assertTrue(Features::enabled('widget.show_guides', $shop));
        $this->assertFalse(Features::enabled('widget.show_on_sale', $shop));
        $this->assertFalse(Features::enabled('widget.show_family', $shop));
        $this->assertNull(Features::overrideFor('widget.show_specs', $shop), 'a part left as the default is not the shop\'s word');

        // The master switch is both places: off turns them off, and the next page request is built again.
        Livewire::test(ProductWidgetSettings::class)->set('data.on', false)->call('save');
        $this->assertFalse(Features::enabled('widget.on_products', $shop));
        $this->assertFalse($this->page('product', '10')->json('enabled'), 'the cached page was forgotten');

        // On again with both places off: both come back.
        Livewire::test(ProductWidgetSettings::class)->assertSet('data.on', false)->set('data.on', true)->call('save');
        $this->assertTrue(Features::enabled('widget.on_products', $shop));
        $this->assertTrue(Features::enabled('widget.on_content', $shop));
    }

    public function test_whatsapp_needs_a_number_keeps_the_usual_words_and_reaches_the_product_page(): void
    {
        $this->assertNull($this->page('product', '10')->json('contact'), 'cached without WhatsApp');

        $screen = Livewire::test(WhatsappSettings::class)
            ->assertSee('וואטסאפ')
            ->assertSee('הדרך של הגולש להגיע לצוות כשהעוזר לא עוזר. מופיע רק בשעות המענה, עם השאלה כבר כתובה.')
            ->assertSee('ההודעה שנפתחת')
            ->assertSee('כשאין התאמה בחיפוש')
            ->assertSee('להציע השארת פרטים')
            ->assertSet('data.title', 'רוצים לוודא? הצוות זמין בוואטסאפ')
            ->assertSet('data.button', 'לשאול את הצוות')
            ->assertSet('data.when_offline', 'details');

        // Not on without a number: nothing is saved.
        $screen->set('data.whatsapp', true)->call('save')->assertSee('בלי מספר אי אפשר להפעיל וואטסאפ.');
        $this->assertFalse(Features::enabled('widget.whatsapp', $this->shop->id));

        $screen->set('data.number', '050-123 4567')
            ->set('data.message', 'היי, שאלה על :product – :question')
            ->set('data.when_offline', 'show')
            ->set('data.search_whatsapp', false)
            ->call('save')
            ->assertSet('data.number', '972501234567');

        $shop = $this->shop->id;
        $this->assertSame('972501234567', Settings::overrideFor('widget.whatsapp_number', $shop), 'an Israeli mobile, in the form WhatsApp links need');
        $this->assertNull(Settings::overrideFor('widget.whatsapp_title', $shop), 'the usual title is not the shop\'s word');
        $this->assertSame('היי, שאלה על :product – :question', Settings::get('widget.whatsapp_message', $shop));
        $this->assertFalse(Features::enabled('assistant.search_whatsapp', $shop));

        $contact = $this->page('product', '10')->json('contact');
        $this->assertSame('972501234567', $contact['number'], 'the cached page was forgotten');
        $this->assertSame('show', $contact['when_offline']);
        $this->assertFalse($contact['hide_when_offline']);
        $this->assertSame('לשאול את הצוות', $contact['button']);

        // The search offer is off, so a question with no answer here is not sent to WhatsApp.
        $bank = $this->page('product', '10')->json();
        $this->assertArrayHasKey('lead_form', $bank);
        $this->assertSame(Features::enabled('shoppers.callbacks', $shop) ? 'details' : 'none', $bank['no_answer']);
        Features::override('assistant.search_whatsapp', true, $shop);
        Livewire::test(WhatsappSettings::class)->call('save');
        $this->assertSame('team', $this->page('product', '10')->json('no_answer'));
    }

    public function test_the_hours_page_edits_each_day_and_refuses_a_broken_one(): void
    {
        $screen = Livewire::test(OpeningHours::class)
            ->assertSee('מתי הצוות עונה')
            ->assertSee('בשעות האלה Lets מציע וואטסאפ; מחוץ להן, השארת פרטים')
            ->assertSee('שמירה')
            ->assertSet('data.days.sunday.open', true)
            ->assertSet('data.days.sunday.from', '09:00')
            ->assertSet('data.days.friday.open', false);

        $screen->set('data.days.friday.open', true)->set('data.days.friday.from', '9')->set('data.days.friday.until', '')->call('save')
            ->assertSee('השעות של שישי לא תקינות');
        $this->assertSame('', Settings::get('widget.hours_friday', $this->shop->id), 'nothing saved');

        $screen->set('data.days.sunday.from', '07:00')->set('data.days.sunday.until', '17:00')
            ->set('data.days.monday.open', false)
            ->set('data.days.friday.from', '07:00')->set('data.days.friday.until', '13:00')
            ->call('save');

        $shop = $this->shop->id;
        $this->assertSame('07:00-17:00', Settings::get('widget.hours_sunday', $shop));
        $this->assertSame('', Settings::get('widget.hours_monday', $shop));
        $this->assertSame('07:00-13:00', Settings::get('widget.hours_friday', $shop));
        $this->assertNull(Settings::overrideFor('widget.hours_tuesday', $shop), 'a day left as the default is not the shop\'s word');
    }

    public function test_the_settings_menu_has_the_new_pages_and_not_the_page_editor(): void
    {
        $this->assertFalse(ProductPage::shouldRegisterNavigation(), 'reached from "עריכה לפי עמוד"');
        $this->get(ProductPage::getUrl())->assertOk();

        foreach ([[ProductWidgetSettings::class, 'shoppers', 40], [WhatsappSettings::class, 'team', 70], [OpeningHours::class, 'team', 90]] as [$page, $group, $sort]) {
            $this->assertTrue($page::shouldRegisterNavigation());
            $this->assertSame($group, $page::$sideGroup);
            $this->assertSame($sort, $page::getNavigationSort());
            $this->get($page::getUrl())->assertOk();
        }
    }

    private function page(string $type, string $id): TestResponse
    {
        return $this->get("/api/v1/widget/{$this->site}/page?type={$type}&id={$id}&locale=he", ['Origin' => 'https://store.test']);
    }
}
