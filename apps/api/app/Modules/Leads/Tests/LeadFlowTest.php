<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Mail\LeadReceivedMail;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\FlowMachine;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The part of the conversation that is trying to get somewhere, which is all code.
 */
final class LeadFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok_leads_flow_abcdefghijklmnopqrst';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        Features::override('leads.enabled', true, $this->shop->id);

        app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::query()->create([
            'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Advice,
            'offer' => 'שיחת ייעוץ של 15 דקות', 'promise' => 'נחזור תוך יום',
            'consent' => 'אני מאשר/ת שהפרטים יישמרו.', 'active' => true,
            'fields' => [
                ['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true],
                ['type' => 'name', 'key' => 'name', 'label' => 'שם', 'required' => false],
            ],
        ]));
    }

    public function test_it_asks_one_field_at_a_time_then_consent_then_keeps_the_lead(): void
    {
        $first = $this->step([]);
        $first->assertOk();
        $this->assertSame(FlowMachine::FIELD, $first->json('data.state'));
        $this->assertSame('phone', $first->json('data.field.key'), 'the phone before anything else');
        $this->assertSame('שיחת ייעוץ של 15 דקות', $first->json('data.offer'));

        $second = $this->step(['phone' => '050-123-4567']);
        $this->assertSame('name', $second->json('data.field.key'));

        // Consent comes last: agreeing before seeing what is asked for is not agreeing.
        $third = $this->step(['phone' => '050-123-4567', 'name' => 'אביעד']);
        $this->assertSame(FlowMachine::CONSENT, $third->json('data.state'));
        $this->assertSame('אני מאשר/ת שהפרטים יישמרו.', $third->json('data.consent'));
        $this->assertSame(0, $this->countLeads(), 'nothing is kept before the consent');

        $done = $this->step(['phone' => '050-123-4567', 'name' => 'אביעד'], consent: true, asked: 2);
        $this->assertSame(FlowMachine::DONE, $done->json('data.state'));
        $this->assertSame('נחזור תוך יום', $done->json('data.promise'));

        $lead = $this->onlyLead();
        $this->assertSame('phone', $lead->channel);
        $this->assertSame('972501234567', $lead->contact, 'kept as the parser reads it, encrypted at rest');
        $this->assertSame(['name' => 'אביעד'], $lead->answers);
        $this->assertSame('אני מאשר/ת שהפרטים יישמרו.', $lead->consent_wording, 'the wording travels with the lead');
        $this->assertSame(100, $lead->quality, 'everything given, and they asked first');
    }

    public function test_a_phone_that_is_not_a_phone_is_refused_with_the_field_to_try_again(): void
    {
        $answer = $this->step(['phone' => 'לא מספר']);

        $answer->assertOk();
        $this->assertSame(FlowMachine::FIELD, $answer->json('data.state'));
        $this->assertSame('not_a_phone', $answer->json('data.error'));
        $this->assertSame(0, $this->countLeads());
    }

    public function test_nothing_the_flow_did_not_ask_for_can_be_posted_into_a_lead(): void
    {
        $this->step(
            ['phone' => '0501234567', 'name' => 'אביעד', 'salary' => '40000', 'notes' => 'x'],
            consent: true,
        );

        $this->assertSame(['name' => 'אביעד'], $this->onlyLead()->answers, 'only the shop\'s own fields');
    }

    public function test_saying_no_ends_it_and_keeps_nothing(): void
    {
        $answer = $this->step(['phone' => '0501234567'], declined: true);

        $this->assertSame(FlowMachine::DECLINED, $answer->json('data.state'));
        $this->assertSame(0, $this->countLeads());
    }

    public function test_a_phone_alone_is_kept_when_they_stop_answering_and_agree(): void
    {
        // A flow that also wants a name: they give the phone, then say no to the name.
        app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::query()->update(['fields' => [
            ['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true],
            ['type' => 'name', 'key' => 'name', 'label' => 'שם', 'required' => true],
        ]]));

        $stopped = $this->step(['phone' => '0501234567'], declined: true, extra: ['at' => FlowMachine::FIELD]);
        $this->assertSame(FlowMachine::CONSENT, $stopped->json('data.state'), 'asked to agree, not dropped');
        $this->assertTrue($stopped->json('data.enough'));
        $this->assertSame(0, $this->countLeads(), 'nothing kept before the consent');

        $done = $this->step(['phone' => '0501234567'], consent: true, extra: ['enough' => true]);
        $this->assertSame(FlowMachine::DONE, $done->json('data.state'));
        $this->assertSame('972501234567', $this->onlyLead()->contact);
        $this->assertNull($this->onlyLead()->answers);
    }

    public function test_no_to_the_consent_or_before_any_contact_keeps_nothing(): void
    {
        $this->assertSame(FlowMachine::DECLINED, $this->step(['phone' => '0501234567'], declined: true, extra: ['at' => FlowMachine::CONSENT, 'enough' => true])->json('data.state'));
        $this->assertSame(FlowMachine::DECLINED, $this->step([], declined: true, extra: ['at' => FlowMachine::FIELD])->json('data.state'));
        // "enough" without a way to reach them asks for the phone as before
        $this->assertSame('phone', $this->step([], extra: ['enough' => true])->json('data.field.key'));
        $this->assertSame(0, $this->countLeads());
    }

    public function test_the_same_person_asking_twice_on_one_page_is_one_lead(): void
    {
        $this->step(['phone' => '0501234567'], consent: true);
        $this->step(['phone' => '050-123-4567', 'name' => 'אביעד'], consent: true);

        $this->assertSame(1, $this->countLeads(), 'a second ask updates the first');
        $this->assertSame(['name' => 'אביעד'], $this->onlyLead()->answers);
    }

    public function test_a_shop_with_no_flow_collects_nothing(): void
    {
        app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::query()->update(['active' => false]));

        $this->step(['phone' => '0501234567'], consent: true)->assertStatus(409);
    }

    public function test_the_flow_is_closed_to_another_origin(): void
    {
        $this->postJson("/api/v1/widget/{$this->site}/lead", ['id' => '10', 'type' => 'product'], [
            'Origin' => 'https://somewhere.else',
        ])->assertStatus(403);
    }

    public function test_the_stores_team_gets_every_lead_by_mail(): void
    {
        Mail::fake();
        $team = [];
        foreach (['owner@store.test' => ShopRole::Owner, 'member@store.test' => ShopRole::Member, 'viewer@store.test' => ShopRole::Viewer] as $email => $role) {
            $user = User::factory()->create(['email' => $email]);
            $user->attachShop($this->shop, $role);
            $team[] = $user;
        }
        Settings::set('leads.notify_email', 'sales@store.test', $this->shop->id);

        $this->step(['phone' => '050-123-4567', 'name' => 'אביעד'], consent: true, asked: 2)->assertOk();

        $sent = Mail::sent(LeadReceivedMail::class)->flatMap(fn (LeadReceivedMail $m) => array_column($m->to, 'address'))->sort()->values()->all();
        $this->assertSame(['member@store.test', 'owner@store.test', 'sales@store.test'], $sent, 'its owners and members and the address it adds; a viewer only looks');
        Mail::assertSent(LeadReceivedMail::class, fn (LeadReceivedMail $m): bool => $m->contact === '972501234567' && $m->answers === [['label' => 'שם', 'value' => 'אביעד']] && $m->panelUrl !== null);

        // the same again sends nothing new; turned off, nothing at all
        $this->step(['phone' => '050-123-4567', 'name' => 'אביעד'], consent: true, asked: 2);
        Mail::assertSentCount(3);
        Settings::set('leads.email_team', false, $this->shop->id);
        $this->step(['phone' => '052-765-4321', 'name' => 'דנה'], consent: true, asked: 2);
        Mail::assertSentCount(3);
    }

    /**
     * @param  array<string, string>  $given
     * @param  array<string, mixed>  $extra
     */
    private function step(array $given, bool $consent = false, bool $declined = false, int $asked = 0, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/widget/{$this->site}/lead", [
            'id' => '10',
            'type' => 'product',
            'vid' => 'anon-abcdefghijklmnopqrstuv',
            'given' => $given,
            'consent' => $consent,
            'declined' => $declined,
            'asked' => $asked,
        ] + $extra, ['Origin' => 'https://store.test']);
    }

    /** The whole query has to run inside the shop's scope, not just be built there. */
    private function countLeads(): int
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): int => Lead::query()->count());
    }

    private function onlyLead(): Lead
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): Lead => Lead::query()->sole());
    }
}
