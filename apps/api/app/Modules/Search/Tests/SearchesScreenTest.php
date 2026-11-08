<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Merchant\Pages\Searches;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\SearchScreen;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Searches screen with a store's real week (the redesign, 11.2026): the four numbers, the days, what is rising, the
 * top searches with what was opened and what needs a fix, the questions with where their answers came from, the phrases
 * that come back, and the store's own search to try.
 */
final class SearchesScreenTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->setTime(10, 0));
        $this->shop = Shop::factory()->create(['name' => 'גואטה', 'currency' => 'ILS']);
        $this->merchant = User::factory()->create();
        $this->merchant->attachShop($this->shop);

        $this->inShop(function (): void {
            $id = $this->shop->id;
            CatalogProduct::query()->create(['shop_id' => $id, 'external_id' => '11', 'hash' => 'h11', 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => 'שמלת פשתן לבנה', 'image_url' => 'https://shop.test/dress.jpg', 'in_stock' => true, 'purchasable' => true]);
            foreach ([['שמלה לחתונה', 2, 30, 0, 12, 4], ['שמלה לחתונה', 10, 10, 0, 3, 1], ['כובע קש', 3, 8, 0, 4, 2], ['סנדלס', 2, 6, 6, 0, 0], ['[photo]', 1, 5, 0, 2, 0]] as [$q, $ago, $n, $empty, $clicks, $added]) {
                SearchTerm::query()->create(['shop_id' => $id, 'day' => now()->subDays($ago)->toDateString(), 'query' => $q, 'searches' => $n, 'empty' => $empty, 'clicks' => $clicks, 'added' => $added, 'last_results' => $empty ? 0 : 5]);
            }
            SearchClick::query()->create(['shop_id' => $id, 'day' => now()->subDays(2)->toDateString(), 'query' => 'שמלה לחתונה', 'item' => 'p:11', 'title' => 'שמלת פשתן לבנה', 'clicks' => 12, 'added' => 4]);
            SearchClick::query()->create(['shop_id' => $id, 'day' => now()->subDays(1)->toDateString(), 'query' => '[photo]', 'item' => 'p:11', 'title' => 'שמלת פשתן לבנה', 'clicks' => 2, 'added' => 1]);
            foreach (['answered', 'answered', 'no_info'] as $outcome) {
                AssistantSearchAsk::query()->create(['shop_id' => $id, 'day' => now()->subDay()->toDateString(), 'question' => 'יש משלוח לאילת?', 'products' => [], 'outcome' => $outcome, 'from' => 'model'])
                    ->forceFill(['created_at' => now()->subDay()])->save();
            }
            AssistantAnswer::query()->create(['shop_id' => $id, 'question' => 'יש משלוח לאילת?', 'question_key' => 'eilat', 'answer' => 'כן.', 'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN,
                'source' => 'store', 'sources' => [['title' => 'משלוחים', 'url' => 'https://shop.test/shipping']], 'prompt_version' => 1, 'asked_count' => 7, 'last_asked_at' => now()->subDay()]);
        });
    }

    public function test_the_screen_reads_the_week_from_the_search(): void
    {
        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/searches")
            ->assertOk()
            ->assertSee('data-kpis', false)
            ->assertSee('חיפושים לפי יום')
            ->assertSee('עולים השבוע')
            ->assertSee('החיפושים המובילים')
            ->assertSee('שמלת פשתן לבנה')
            ->assertSee('מחכה לתיקון', false)
            ->assertSee('מהעמוד ״משלוחים״', false)
            ->assertSee('במילים שלהם')
            ->assertSee('לנסות חיפוש');
    }

    public function test_the_numbers_count_what_search_recorded(): void
    {
        $screen = app(SearchScreen::class);
        $since = now()->subDays(7)->startOfDay();
        $before = $since->copy()->subDays(7);

        $k = $this->inShop(fn () => $screen->kpis($this->shop, $since, now(), $before));
        $this->assertSame([44, 10, 3, 2, 5, 1], [$k['searches'], $k['searches_before'], $k['questions'], $k['answered'], $k['photos'], $k['photo_adds']]);

        $rows = $this->inShop(fn () => $screen->rows($this->shop, $since, now(), $before, 'searched', 8));
        $this->assertSame(['שמלה לחתונה', 'כובע קש', 'סנדלס'], array_column($rows, 'query'));
        $this->assertSame([30, 10, 40, 'שמלת פשתן לבנה'], [$rows[0]['searches'], $rows[0]['before'], $rows[0]['clicked'], $rows[0]['found']]);
        $this->assertTrue($rows[2]['fix'] && $rows[2]['empty'], 'found nothing: waits for a fix');
        $this->assertSame(['סנדלס'], array_column($this->inShop(fn () => $screen->rows($this->shop, $since, now(), $before, 'fix', 8)), 'query'));

        $rising = $this->inShop(fn () => $screen->rising($this->shop, $since, now(), $before, 4));
        $this->assertSame('כובע קש', $rising[0]['query'], 'new this week comes first');

        $words = array_column($this->inShop(fn () => $screen->words($this->shop, $since, now(), 10)), 'phrase');
        $this->assertSame('שמלה לחתונה', $words[0], 'the pair said as one');

        $q = $this->inShop(fn () => $screen->questions($this->shop, $since, now(), 5))[0];
        $this->assertSame(['יש משלוח לאילת?', 7, true, 'page', 'משלוחים'], [$q['question'], $q['times'], $q['answered'], $q['from'], $q['where']]);
    }

    public function test_the_owner_switches_the_days_and_the_order_and_tries_a_search(): void
    {
        $this->actingAs($this->merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);

        Livewire::test(Searches::class)
            ->assertSet('days', 7)
            ->call('measure', 'adds')->assertSet('measure', 'adds')
            ->call('sortBy', 'fix')->assertSet('sort', 'fix')->assertSee('סנדלס')
            ->call('sortBy', 'nonsense')->assertSet('sort', 'searched')
            ->call('tryIt', 'שמלה')->assertSet('tryQ', 'שמלה')->assertSee('data-try', false);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function inShop(callable $run): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop->id, $run);
    }
}
