<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Filament\Operator\Pages\ShopTimeline;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operator's timeline: what every module reports happened in a shop, by day. As Aviad asked
 * (2026-10-18): the same timeline the shop will get, but with the AI costs, operators only. Each
 * module proves its own entries in its own tests; this one proves the page and the access.
 */
final class ShopTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_operator_reaches_it(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->shops()->attach($shop->id, ['role' => 'owner']);

        $this->actingAs($merchant)->get('/operator/timeline')->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get('/operator/timeline?shop='.$shop->slug)->assertOk();
    }

    public function test_a_user_who_joined_the_shop_is_on_it_and_a_kind_can_be_picked(): void
    {
        $shop = Shop::factory()->create(['name' => 'חומרי בניין']);
        $other = Shop::factory()->create();
        $shira = User::factory()->create(['name' => 'שירה']);
        $shira->shops()->attach($shop->id, ['role' => 'staff']);
        $dan = User::factory()->create(['name' => 'דן']);
        $dan->shops()->attach($other->id, ['role' => 'owner']);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $page = Livewire::test(ShopTimeline::class, ['shop' => $shop->slug])
            ->assertSeeHtml('data-timeline-entry="admin.user.'.$shira->id.'"')
            ->assertSee('שירה')
            ->assertDontSee('דן')
            ->assertSeeHtml('data-timeline-cost');

        $page->call('pick', 'scan')->assertDontSeeHtml('data-timeline-entry="admin.user.'.$shira->id.'"');
        $page->call('pick', 'scan')->assertSeeHtml('data-timeline-entry="admin.user.'.$shira->id.'"');
    }
}
