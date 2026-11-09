<?php

namespace App\Modules\Leads\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ReplyLink;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\Shoppers;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Models\ShopperVisitor;
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

    public function test_each_name_opens_the_visit_they_asked_from_and_shows_when_they_asked(): void
    {
        [$leadVisit, $callbackVisit] = app(TenantContext::class)->run($this->shop->id, function (): array {
            $this->lead->forceFill(['visitor_hash' => 'vh-jonah'])->save();
            ShopperVisitor::query()->create(['shop_id' => $this->shop->id, 'visitor_hash' => 'vh-dana', 'identity_id' => $this->callback->identity_id, 'verified' => false, 'linked_at' => now()->subHours(2)]);
            $visit = fn (string $visitor, int $minutesAgo): int => (int) AnalyticsVisit::query()->create([
                'shop_id' => $this->shop->id, 'session_id' => 's-'.$visitor, 'visitor_hash' => $visitor, 'visit_number' => 1,
                'started_at' => now()->subMinutes($minutesAgo), 'last_at' => now()->subMinutes($minutesAgo - 5), 'source' => 'direct', 'device' => 'phone',
                'pages' => 2, 'steps' => 2, 'did' => ['search'], 'preview' => false,
            ])->id;

            return [$visit('vh-jonah', 10), $visit('vh-dana', 125)];
        });

        $asked = $this->callback->created_at->copy()->timezone($this->shop->timezone ?: 'Asia/Jerusalem')->format('M j, H:i');
        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/shoppers")
            ->assertOk()
            ->assertSee('session='.$leadVisit, false)
            ->assertSee('session='.$callbackVisit, false)
            ->assertSee('See their visit')
            ->assertSee($asked);
    }

    public function test_each_request_says_the_product_it_was_asked_about(): void
    {
        // Aviad, 9.11: "so I understand which product or page the question is about"
        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '11', 'type' => 'simple', 'status' => 'publish', 'title' => 'Linen dress, olive', 'url' => 'https://store.test/linen-dress', 'image_url' => 'https://store.test/linen.jpg', 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h11', 'payload' => []]);
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '10', 'type' => 'simple', 'status' => 'publish', 'title' => 'Gift box', 'url' => 'https://store.test/gift-box', 'price' => 20, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h10', 'payload' => []]);
        });

        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/shoppers")
            ->assertOk()
            ->assertSeeInOrder(['Do you gift wrap?', 'Asked about:', 'Gift box', 'Can I order the linen dress in olive green?', 'Asked about:', 'Linen dress, olive'])
            ->assertSee('https://store.test/linen-dress', false)
            ->assertSee('https://store.test/linen.jpg', false);
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
