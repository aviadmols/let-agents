<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Contracts\ReadsContacts;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lead is kept its shop's leads.retention_days (a year), then goes with the person's details in it; and a shopper
 * who asks the store to erase them (Shopify's customers/redact) takes every lead they left by that email or phone.
 */
final class LeadRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
    }

    public function test_a_lead_older_than_its_shops_keeping_days_is_deleted(): void
    {
        $old = $this->lead('dana@example.com', now()->subDays(400));
        $recent = $this->lead('noa@example.com', now()->subDays(30));
        $other = Shop::factory()->create();
        Settings::set('leads.retention_days', 3650, $other->id);
        $kept = $this->lead('tal@example.com', now()->subDays(400), $other);

        $this->artisan('leads:forget-old')->assertSuccessful();

        $left = app(TenantContext::class)->runUnscoped(fn () => Lead::query()->pluck('id')->all());
        $this->assertNotContains($old->id, $left, 'a year and more: gone');
        $this->assertContains($recent->id, $left);
        $this->assertContains($kept->id, $left, 'a shop that keeps them longer keeps them');
    }

    public function test_a_shopper_asking_to_be_erased_takes_their_leads_by_email_or_phone(): void
    {
        $byEmail = $this->lead('Dana@Example.com');
        $byPhone = $this->lead('0501234567');
        $someoneElse = $this->lead('noa@example.com');

        event(new ShopperDataRequested($this->shop->id, 'report', 'dana@example.com'));
        $this->assertSame(3, $this->leads(), 'a report erases nothing');

        event(new ShopperDataRequested($this->shop->id, 'redact', 'dana@example.com', '7', '+972-50-123-4567'));

        $left = app(TenantContext::class)->run($this->shop->id, fn () => Lead::query()->pluck('id')->all());
        $this->assertSame([$someoneElse->id], $left);
        $this->assertNotContains($byEmail->id, $left);
        $this->assertNotContains($byPhone->id, $left);
    }

    private function leads(): int
    {
        return app(TenantContext::class)->run($this->shop->id, fn () => Lead::query()->count());
    }

    private function lead(string $typed, mixed $at = null, ?Shop $shop = null): Lead
    {
        $shop ??= $this->shop;
        $contact = app(ReadsContacts::class)->read($typed, $shop->id);

        return app(TenantContext::class)->run($shop->id, function () use ($shop, $contact, $at): Lead {
            $flow = LeadFlow::query()->firstOrCreate(
                ['shop_id' => $shop->id, 'version' => 1],
                ['goal' => LeadGoal::Advice, 'offer' => 'x', 'consent' => 'y', 'active' => true, 'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]]],
            );
            $lead = Lead::query()->create([
                'shop_id' => $shop->id, 'flow_id' => $flow->id, 'page_type' => 'product', 'page_external_id' => '10',
                'channel' => $contact['channel'], 'contact_hash' => $contact['hash'], 'contact' => $contact['value'], 'contact_masked' => $contact['masked'],
                'consented_at' => now(), 'consent_wording' => 'y', 'quality' => 75, 'status' => Lead::NEW,
            ]);

            if ($at !== null) {
                $lead->forceFill(['created_at' => $at])->save();
            }

            return $lead;
        });
    }
}
