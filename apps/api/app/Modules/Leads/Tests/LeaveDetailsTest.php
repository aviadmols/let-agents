<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Leads\Contracts\DescribesLeadForm;
use App\Modules\Leads\Filament\Merchant\Pages\LeaveDetails;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\FlowMachine;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · Leaving details: the fields a shopper is asked, in order and each required or not, from the
 * bank or the shop's own (a way back always first), its heading and promise; and the storefront's form
 * asks exactly those, with the question that had no answer kept on the lead.
 */
final class LeaveDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok_leave_details_abcdefghijklmnop';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold', 'content_locale' => 'he']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
    }

    public function test_it_renders_the_design_with_what_the_shop_asks_today(): void
    {
        Livewire::test(LeaveDetails::class)
            ->assertOk()
            ->assertSee('השארת פרטים')
            ->assertSee('כשאין וואטסאפ, או מחוץ לשעות: הגולש משאיר דרך ליצור קשר.')
            ->assertSee('מה מבקשים')
            ->assertSee('הסדר כאן הוא סדר השאלות.')
            ->assertSee('בחרו שדות מהבנק למטה')
            ->assertSee('שדה משלכם, למשל: מידות הפלטה')
            ->assertSee('להוסיף')
            ->assertSee('סוג הפרויקט')
            ->assertSee('מה מבטיחים')
            ->assertSee('מוצרים שראיתם לאחרונה')
            ->assertSee('שמירת השינויים')
            ->assertSet('data.on', true)
            ->assertSet('data.recent', true)
            ->assertSet('data.title', 'נחזור אליכם עם תשובה')
            ->assertSet('data.promise', 'הצוות חוזר תוך יום עסקים')
            ->assertSet('data.fields', [
                ['key' => 'phone', 'type' => 'phone', 'label' => 'טלפון', 'required' => true],
                ['key' => 'name', 'type' => 'name', 'label' => 'שם', 'required' => false],
            ]);
    }

    public function test_saving_makes_the_fields_a_new_version_with_a_way_back_first(): void
    {
        $page = Livewire::test(LeaveDetails::class)
            ->set('data.fields', [
                ['key' => 'city', 'type' => 'text', 'label' => 'עיר', 'required' => true],
                ['key' => 'email', 'type' => 'email', 'label' => 'מייל', 'required' => false],
                ['key' => 'own_k1', 'type' => 'phone', 'label' => '  מידות   הפלטה ', 'required' => false],
                ['key' => 'salary', 'type' => 'text', 'label' => 'משכורת', 'required' => true],
                ['key' => 'question', 'type' => 'text', 'label' => 'שאלה', 'required' => true],
            ])
            ->set('data.title', 'נחזור אליכם היום')
            ->set('data.promise', 'נחזור תוך שעתיים')
            ->set('data.recent', false)
            ->call('save')
            ->assertHasNoErrors();

        $flow = $this->flow();
        $this->assertSame(1, $flow->version);
        $this->assertSame([
            ['key' => 'email', 'type' => 'email', 'label' => 'מייל', 'required' => true],
            ['key' => 'city', 'type' => 'text', 'label' => 'עיר', 'required' => true],
            ['key' => 'own_k1', 'type' => 'text', 'label' => 'מידות הפלטה', 'required' => false],
        ], $flow->asked(), 'a way back first and required; a field of the shop\'s own is text; nothing unknown');
        $this->assertSame('נחזור תוך שעתיים', $flow->promise);
        $this->assertSame('נחזור אליכם היום', Settings::get('leads.form_title', $this->shop->id));
        $this->assertFalse(Features::enabled('shoppers.recent_products', $this->shop->id));
        $page->assertSet('data.fields.0.key', 'email');

        // Saved again as it is: no new version. A change: a new one, the old one kept.
        $page->call('save');
        $this->assertSame(1, $this->flow()->version);
        $page->set('data.fields.1.required', false)->call('save');
        $this->assertSame(2, $this->flow()->version);
        $this->assertFalse($this->flow()->asked()[1]['required']);
        $this->assertSame(2, app(TenantContext::class)->run($this->shop->id, fn (): int => LeadFlow::query()->count()));

        // The built-in heading is not kept as the shop's own.
        $page->set('data.title', 'נחזור אליכם עם תשובה')->call('save');
        $this->assertNull(Settings::overrideFor('leads.form_title', $this->shop->id));
    }

    public function test_the_storefront_form_asks_exactly_those_fields_and_keeps_the_question(): void
    {
        Livewire::test(LeaveDetails::class)
            ->set('data.fields', [
                ['key' => 'phone', 'type' => 'phone', 'label' => 'טלפון', 'required' => true],
                ['key' => 'city', 'type' => 'text', 'label' => 'עיר', 'required' => true],
                ['key' => 'name', 'type' => 'name', 'label' => 'שם', 'required' => false],
            ])
            ->call('save');

        $form = app(DescribesLeadForm::class)->forShop($this->shop->id, 'he');
        $this->assertSame(['phone', 'city', 'name'], array_column($form['fields'], 'key'));
        $this->assertSame([true, true, false], array_column($form['fields'], 'required'));
        $this->assertSame(['נחזור אליכם עם תשובה', 'הצוות חוזר תוך יום עסקים'], [$form['title'], $form['promise']]);

        // Leads on pages are off; leaving details about an unanswered question is still open.
        $this->assertFalse(Features::enabled('leads.enabled', $this->shop->id));
        $first = $this->step([]);
        $this->assertSame(['field', 'phone', 'נחזור אליכם עם תשובה'], [$first->json('data.state'), $first->json('data.field.key'), $first->json('data.offer')]);

        // All at once, in the form: a required field left empty is asked for.
        $missing = $this->step(['phone' => '050-123-4567', 'city' => '', 'name' => ''], consent: true);
        $this->assertSame([FlowMachine::FIELD, 'city', 'required'], [$missing->json('data.state'), $missing->json('data.field.key'), $missing->json('data.error')]);

        $done = $this->step(['phone' => '050-123-4567', 'city' => 'אילת', 'name' => ''], consent: true);
        $this->assertSame(FlowMachine::DONE, $done->json('data.state'));
        $this->assertSame('הצוות חוזר תוך יום עסקים', $done->json('data.promise'));
        $lead = app(TenantContext::class)->run($this->shop->id, fn (): Lead => Lead::query()->sole());
        $this->assertSame(['search', 'ask-1'], [$lead->page_type, $lead->page_external_id]);
        $this->assertSame(['city' => 'אילת', 'question' => 'יש משלוח לאילת?'], $lead->answers);

        // The shop stopped taking details: no form, and the address is closed to questions.
        Livewire::test(LeaveDetails::class)->set('data.on', false)->call('save');
        $this->assertNull(app(DescribesLeadForm::class)->forShop($this->shop->id, 'he'));
        $this->step([])->assertForbidden();
    }

    public function test_the_defaults_follow_the_shops_language_also_after_saving_them_in_another(): void
    {
        // A Hebrew store keeps Hebrew; saved untouched, nothing of the built-in words is kept as its own.
        Livewire::test(LeaveDetails::class)->call('save')->assertHasNoErrors();
        $this->assertNull($this->flow()->promise, 'the built-in promise is not the shop\'s own');
        $this->assertSame(['טלפון', 'שם'], array_column($this->flow()->asked(), 'label'));

        // The store is English now (its language changed, or the install read it so): the saved Hebrew defaults read English.
        $this->shop->forceFill(['content_locale' => 'en'])->save();
        Filament::setTenant($this->shop->fresh());
        app()->setLocale('en');

        Livewire::test(LeaveDetails::class)
            ->assertSet('data.title', 'We\'ll get back to you with an answer')
            ->assertSet('data.promise', 'The team gets back within a business day')
            ->assertSet('data.fields', [
                ['key' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'required' => true],
                ['key' => 'name', 'type' => 'name', 'label' => 'Name', 'required' => false],
            ])
            ->assertSee('Kind of project');

        $form = app(DescribesLeadForm::class)->forShop($this->shop->id, 'en');
        $this->assertSame(['Phone', 'Name'], array_column($form['fields'], 'label'));
        $this->assertSame('The team gets back within a business day', $form['promise']);
        $this->assertStringStartsWith('I agree that the details I leave', $form['consent'], 'the built-in consent in the shopper\'s language');

        // A label of the shop's own stays as it wrote it, in any language.
        Livewire::test(LeaveDetails::class)
            ->set('data.fields', [['key' => 'phone', 'type' => 'phone', 'label' => 'נייד', 'required' => true]])
            ->call('save');
        $this->assertSame(['נייד'], array_column(app(DescribesLeadForm::class)->forShop($this->shop->id, 'en')['fields'], 'label'));
    }

    public function test_a_store_in_a_language_without_these_words_reads_english_never_hebrew(): void
    {
        $this->shop->forceFill(['content_locale' => 'fr'])->save();
        Filament::setTenant($this->shop->fresh());

        Livewire::test(LeaveDetails::class)
            ->assertSet('data.title', 'We\'ll get back to you with an answer')
            ->assertSet('data.fields.0.label', 'Phone');
    }

    private function flow(): LeadFlow
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): LeadFlow => LeadFlow::inForce($this->shop->id));
    }

    /** @param array<string, string> $given */
    private function step(array $given, bool $consent = false): TestResponse
    {
        return $this->postJson('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/lead', [
            'id' => 'ask-1', 'type' => 'search', 'vid' => 'anon-abcdefghijklmnopqrstuv', 'locale' => 'he',
            'question' => 'יש משלוח לאילת?', 'given' => $given, 'consent' => $consent,
        ], ['Origin' => 'https://store.test']);
    }
}
