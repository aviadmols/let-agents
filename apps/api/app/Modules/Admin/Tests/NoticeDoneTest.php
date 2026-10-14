<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Filament\Merchant\Pages\ShopNotices;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A notice the manager marked as done leaves the home, the list and the badge, waits in the done
 * tab, and comes back from there. Only for that shop.
 */
final class NoticeDoneTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        Features::override('shortlist.wishlist', false, $this->shop->id);

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he');
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        Filament::setTenant($this->shop);

        $this->key = collect(app(Home::class)->notices($this->shop))->first(fn (ShopNotice $n): bool => $n->kind === ShopNotice::SETUP)->key;
    }

    public function test_marked_done_it_leaves_the_list_and_comes_back_from_the_done_tab(): void
    {
        $before = ShopNotices::getNavigationBadge();

        Livewire::test(ShopNotices::class)
            ->assertSeeHtml('data-notice="'.$this->key.'"')
            ->assertSee('סמנו כבוצע')
            ->call('markDone', $this->key)
            ->assertDontSeeHtml('data-notice="'.$this->key.'"')
            ->call('show', 'done')
            ->assertSeeHtml('data-notice="'.$this->key.'"')
            ->assertSee('החזירו לרשימה');

        $this->assertNotContains($this->key, array_map(fn (ShopNotice $n): string => $n->key, app(Home::class)->notices($this->shop)));
        $this->assertNotSame($before, ShopNotices::getNavigationBadge(), 'the badge counts one less');

        Livewire::test(ShopNotices::class)->call('undo', $this->key);
        $this->assertContains($this->key, array_map(fn (ShopNotice $n): string => $n->key, app(Home::class)->notices($this->shop)));
    }

    public function test_a_card_on_the_home_is_marked_done_there_and_only_for_this_shop(): void
    {
        $other = Shop::factory()->create();
        Features::override('shortlist.wishlist', false, $other->id);

        Livewire::test(ShopOverview::class)
            ->assertSeeHtml('data-card="'.$this->key.'"')
            ->call('markDone', $this->key)
            ->assertDontSeeHtml('data-card="'.$this->key.'"');

        $this->assertContains($this->key, array_map(fn (ShopNotice $n): string => $n->key, app(Home::class)->notices($other)));
    }
}
