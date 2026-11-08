<?php

namespace App\Modules\Leads\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ReplyLink;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\Shoppers;
use App\Modules\Admin\Models\User;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Waiting for you" on the Shoppers screen (the redesign, 11.2026): the shoppers who left their details when the
 * assistant did not know and the lead forms not handled yet, in one list, the newest first. Reply opens WhatsApp or an
 * email with the question quoted, and the contact never sits on the page; "Mark as answered" takes them off. A viewer
 * of the shop can read the list but not reply.
 */
final class ShoppersWaitingTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $merchant;

    private ShopperCallback $callback;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
        $this->merchant = User::factory()->create(['locale' => 'en']);
        $this->merchant->attachShop($this->shop);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $identity = ShopperIdentity::query()->create([
                'shop_id' => $this->shop->id, 'channel' => 'phone', 'contact_hash' => hash('sha256', 'dana'),
                'contact' => '972501234567', 'contact_masked' => '****4567', 'consented_at' => now(), 'consent_version' => 'v1', 'last_seen_at' => now(),
            ]);
            $this->callback = ShopperCallback::query()->create([
                'shop_id' => $this->shop->id, 'identity_id' => $identity->id, 'name' => 'Dana R.', 'question' => 'Can I order the linen dress in olive green?',
                'page_type' => 'product', 'page_id' => '11',
            ]);
            $this->callback->forceFill(['created_at' => now()->subHours(2)])->save();

            $flow = LeadFlow::query()->create([
                'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Advice, 'offer' => 'x', 'consent' => 'y', 'active' => true,
                'fields' => [['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true]],
            ]);
            $this->lead = Lead::query()->create([
                'shop_id' => $this->shop->id, 'flow_id' => $flow->id, 'page_type' => 'product', 'page_external_id' => '10',
                'channel' => 'email', 'contact_hash' => hash('sha256', 'jonah'), 'contact' => 'jonah@example.com', 'contact_masked' => 'j****@example.com',
                'answers' => ['name' => 'Jonah K.', 'question' => 'Do you gift wrap?'], 'consented_at' => now(), 'consent_wording' => 'y', 'quality' => 75, 'status' => Lead::NEW,
            ]);
        });
    }

    public function test_both_kinds_wait_in_one_list_the_newest_first_and_no_contact_on_the_page(): void
    {
        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/shoppers")
            ->assertOk()
            ->assertSeeInOrder(['Jonah K.', 'Do you gift wrap?', 'Reply by email', 'Dana R.', 'Can I order the linen dress in olive green?', 'Reply on WhatsApp'])
            ->assertDontSee('972501234567')
            ->assertDontSee('jonah@example.com');
    }

    public function test_reply_opens_the_chat_with_the_question_and_records_who_looked(): void
    {
        $this->onPage();

        Livewire::test(Shoppers::class)
            ->call('replyTo', 'callback:'.$this->callback->id)
            ->assertSee('Dana R.');

        $link = ReplyLink::make('phone', '972501234567', 'Dana R.', 'Can I order the linen dress in olive green?');
        $this->assertStringStartsWith('https://wa.me/972501234567?text=', $link);
        $this->assertStringContainsString(rawurlencode('Hi Dana R., about your question'), $link);
        $this->assertStringStartsWith('mailto:jonah@example.com?subject=', ReplyLink::make('email', 'jonah@example.com', null, 'Do you gift wrap?'));
        $this->assertNull(ReplyLink::make('phone', '12', null, 'x'));

        Livewire::test(Shoppers::class)->call('replyTo', 'lead:'.$this->lead->id);
        $this->assertSame($this->merchant->id, app(TenantContext::class)->run($this->shop->id, fn () => $this->lead->fresh()->seen_by));
    }

    public function test_mark_as_answered_takes_them_off_the_list(): void
    {
        $this->onPage();

        Livewire::test(Shoppers::class)
            ->call('answered', 'callback:'.$this->callback->id)
            ->call('answered', 'lead:'.$this->lead->id)
            ->assertSee('No one’s waiting', false);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $this->assertNotNull($this->callback->fresh()->answered_at);
            $this->assertSame(Lead::HANDLED, $this->lead->fresh()->status);
        });
    }

    public function test_a_viewer_reads_the_list_but_does_not_reply(): void
    {
        $viewer = User::factory()->create(['locale' => 'en']);
        $viewer->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($viewer);
        app()->setLocale('en');
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);

        Livewire::test(Shoppers::class)->assertSee('Dana R.')->call('answered', 'callback:'.$this->callback->id);

        $this->assertNull(app(TenantContext::class)->run($this->shop->id, fn () => $this->callback->fresh()->answered_at));
    }

    private function onPage(): void
    {
        $this->actingAs($this->merchant);
        app()->setLocale('en');
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);
    }
}
