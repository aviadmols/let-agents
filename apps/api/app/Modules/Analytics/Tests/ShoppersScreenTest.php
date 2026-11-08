<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Merchant\Pages\Shoppers;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Analytics\Support\ShoppersVisits;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Shoppers screen's visits (the redesign, 11.2026): how many visits and how many searched, where the ones with a
 * search came from, each visit as a short story that ends in an order, a cart or a save, and how many orders held a
 * product. A store with nothing yet sees the empty cards.
 */
final class ShoppersScreenTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $merchant;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->setTime(10, 0));
        $this->shop = Shop::factory()->create(['currency' => 'ILS']);
        $this->merchant = User::factory()->create();
        $this->merchant->attachShop($this->shop);

        $this->inShop(function (): void {
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '11', 'hash' => 'h11', 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => 'שמלת פשתן', 'image_url' => 'https://shop.test/dress.jpg', 'price' => 128, 'in_stock' => true, 'purchasable' => true]);
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '12', 'hash' => 'h12', 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => 'תיק קלוע', 'image_url' => 'https://shop.test/bag.jpg', 'price' => 92, 'in_stock' => true, 'purchasable' => true]);

            // from Instagram on an iPhone: viewed a page, searched, viewed the dress twice, asked, bought
            $this->visit('instagram', ['search', 'ask', 'order'], 'phone', 'iOS', 128, [
                ['view', null, null], ['search', null, 'שמלה לחתונה'], ['view', '11', null], ['view', '11', null], ['ask', '11', 'יש בטנה?'], ['order', null, null],
            ], minutesAgo: 20);
            // from Google on a computer: a photo search, the bag to the cart
            $this->visit('google', ['photo', 'cart'], 'computer', 'Windows', null, [['photo', null, 'bag'], ['view', '12', null], ['cart', '12', null]], minutesAgo: 60);
            // a returning shopper from Instagram who searched and saved
            $this->visit('instagram', ['search', 'heart'], 'phone', 'Android', null, [['search', null, 'מתנה לאמא'], ['heart', '11', null], ['heart', '12', null]], minutesAgo: 120, number: 2);
            // a direct visit with no search, and a preview that is no visit
            $this->visit('direct', [], 'phone', 'iOS', null, [['view', null, null]], minutesAgo: 180, pages: 4);
            $this->visit('google', ['search'], 'computer', 'Windows', null, [['search', null, 'owner-preview-xq']], minutesAgo: 30, preview: true);

            foreach ([[['11', 1]], [['11', 2], ['12', 1]]] as $items) {
                AnalyticsOrder::query()->create(['shop_id' => $this->shop->id, 'order_ref' => hash('sha256', 'o'.++$this->sequence), 'total' => 100, 'currency' => 'ILS', 'items_count' => 1,
                    'items' => array_map(fn (array $i): array => ['product_id' => $i[0], 'quantity' => $i[1], 'total' => 50.0], $items), 'ordered_at' => now()->subDay()]);
            }
        });
    }

    public function test_the_numbers_and_the_sources_count_the_visits_with_a_search(): void
    {
        $visits = app(ShoppersVisits::class);
        $since = now()->subDays(7);

        $this->assertSame(['visits' => 4, 'searched' => 3], $this->inShop(fn () => $visits->numbers($this->shop, $since, now())));

        $sources = $this->inShop(fn () => $visits->sources($this->shop, $since, now()));
        $this->assertSame([['source' => 'instagram', 'n' => 2, 'pct' => 67], ['source' => 'google', 'n' => 1, 'pct' => 33]], $sources['rows']);
        // too few visits from any source to say who searches most
        $this->assertNull($sources['searchiest']);
    }

    public function test_each_visit_is_a_short_story_that_ends_in_what_it_came_to(): void
    {
        $stories = $this->inShop(fn () => app(ShoppersVisits::class)->stories($this->shop, now()->subDays(7), now(), false, 10));

        $this->assertCount(3, $stories);
        [$bought, $cart, $saved] = $stories;

        $this->assertSame(['instagram', 'iphone', 'order'], [$bought['who'], $bought['device'], $bought['end']['kind']]);
        // the page before the search is left out, and the dress viewed twice is one step
        $this->assertSame(['search', 'view', 'ask', 'order'], array_column($bought['steps'], 'kind'));
        $this->assertSame(['שמלה לחתונה', 'שמלת פשתן'], [$bought['steps'][0]['text'], $bought['steps'][1]['text']]);
        $this->assertStringContainsString('128', $bought['end']['value']);

        $this->assertSame(['photo', 'view', 'cart'], array_column($cart['steps'], 'kind'));
        $this->assertSame(['cart', 'computer'], [$cart['end']['kind'], $cart['device']]);
        $this->assertStringContainsString('92', $cart['end']['value']);

        $this->assertSame(['returning', 'android', 'saved', '2'], [$saved['who'], $saved['device'], $saved['end']['kind'], $saved['end']['value']]);
        $this->assertSame([['kind' => 'search', 'text' => 'מתנה לאמא', 'n' => 1, 'picture' => null], ['kind' => 'heart', 'text' => 'שמלת פשתן', 'n' => 2, 'picture' => 'https://shop.test/dress.jpg']], $saved['steps']);

        // with the visits that did not search: the pages they went through
        $all = $this->inShop(fn () => app(ShoppersVisits::class)->stories($this->shop, now()->subDays(7), now(), true, 10));
        $this->assertSame([['kind' => 'browse', 'text' => null, 'n' => 4, 'picture' => null]], $all[3]['steps']);
        $this->assertNull($all[3]['end']);
    }

    public function test_bought_counts_the_orders_that_held_each_product(): void
    {
        $this->assertSame(['11' => 2, '12' => 1, '99' => 0], $this->inShop(fn () => app(ShoppersVisits::class)->bought($this->shop, ['11', '12', '99'], now()->subDays(7), now())));
    }

    public function test_the_screen_tells_the_week_in_hebrew_and_in_english(): void
    {
        $this->merchant->forceFill(['locale' => 'he'])->save();
        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/shoppers")
            ->assertOk()
            ->assertSee('data-kpis', false)
            ->assertSee('מאיפה הם מגיעים')
            ->assertSee('אינסטגרם')
            ->assertSee('ביקורים אחרונים')
            ->assertSee('גולש מאינסטגרם')
            ->assertSee('שמלה לחתונה')
            ->assertSee('צפה ב<bdi>שמלת פשתן</bdi>', false)
            ->assertSee('גולש חוזר')
            ->assertSee('כל הביקורים')
            ->assertDontSee('owner-preview-xq');

        $this->merchant->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/shoppers")
            ->assertOk()
            ->assertSee('Where they come from')
            ->assertSee('Shopper from Instagram')
            ->assertSee('Searched with a photo')
            ->assertSee('Added to cart')
            ->assertSee('Returning shopper');
    }

    public function test_the_visits_without_a_search_show_on_the_toggle(): void
    {
        $this->merchant->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);
        app()->setLocale('en');

        Livewire::test(Shoppers::class)
            ->assertSet('days', 7)
            ->assertDontSee('Browsed 4 pages without searching')
            ->set('plain', true)
            ->assertSee('Browsed 4 pages without searching');
    }

    public function test_a_store_with_nothing_yet_sees_the_empty_cards(): void
    {
        $empty = Shop::factory()->create();
        $this->merchant->attachShop($empty);
        $this->merchant->forceFill(['locale' => 'en'])->save();

        $this->actingAs($this->merchant)->get("/merchant/{$empty->slug}/shoppers")
            ->assertOk()
            ->assertSee('data-zero-leads', false)
            ->assertSee('No one’s waiting', false)
            ->assertSee('No visits with a search yet')
            ->assertDontSee('data-kpis', false);
    }

    /** @param list<array{0: string, 1: string|null, 2: string|null}> $steps kind, product, words */
    private function visit(string $source, array $did, string $device, string $os, ?float $order, array $steps, int $minutesAgo, int $number = 1, int $pages = 1, bool $preview = false): void
    {
        $start = now()->subMinutes($minutesAgo);
        $visit = AnalyticsVisit::query()->create([
            'shop_id' => $this->shop->id, 'session_id' => 's'.++$this->sequence, 'visitor_hash' => 'v'.$this->sequence, 'visit_number' => $number,
            'started_at' => $start, 'last_at' => $start->copy()->addMinutes(6), 'source' => $source, 'device' => $device, 'os' => $os, 'browser' => 'Safari',
            'pages' => $pages, 'steps' => count($steps), 'did' => $did, 'order_total' => $order, 'preview' => $preview, 'ip' => '10.0.0.'.$this->sequence,
        ]);
        foreach ($steps as $i => [$kind, $item, $label]) {
            AnalyticsVisitStep::query()->create([
                'shop_id' => $this->shop->id, 'visit_id' => $visit->id, 'step_id' => 'st'.$i, 'kind' => $kind, 'at' => $start->copy()->addSeconds(30 * ($i + 1)),
                'page_type' => $item !== null ? 'product' : 'home', 'item' => $item, 'label' => $label, 'number' => $kind === 'cart' ? 1 : null,
            ]);
        }
    }

    private function inShop(callable $run): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop->id, $run);
    }
}
