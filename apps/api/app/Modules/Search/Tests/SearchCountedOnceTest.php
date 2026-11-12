<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\CountSearch;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same words from the same address on the same day are one search (Aviad, 12.11: "גדרות", 20 searches, were a few
 * people searching again). Each is still in the log; the count of the word is once an address a day.
 */
final class SearchCountedOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_search_again_from_the_same_address_counts_once_and_another_address_counts_again(): void
    {
        $shop = Shop::factory()->create();

        app(TenantContext::class)->run((string) $shop->id, function () use ($shop): void {
            $count = app(CountSearch::class);
            foreach (range(1, 4) as $i) {
                $count->search((string) $shop->id, 'גדרות', 0, ['ip' => '10.0.0.1', 'session_id' => 's'.$i]);
            }
            $count->search((string) $shop->id, 'גדרות', 0, ['ip' => '10.0.0.2']);
            $count->search((string) $shop->id, 'בורג', 3, ['ip' => '10.0.0.1']);
            // a search with no address known (the photo's, a server's own) counts each time, as before
            $count->search((string) $shop->id, 'בורג', 3);

            $terms = SearchTerm::query()->get()->keyBy('query');
            $this->assertSame([2, 2], [(int) $terms['גדרות']->searches, (int) $terms['גדרות']->empty]);
            $this->assertSame(2, (int) $terms['בורג']->searches);
            $this->assertSame(6, SearchLog::query()->count(), 'every search is still in the log');
        });

        // the days before, counted again from the log: once an address, and run again it changes nothing
        app(TenantContext::class)->run((string) $shop->id, function () use ($shop): void {
            $day = now()->subDays(3)->startOfDay();
            SearchTerm::query()->create(['shop_id' => $shop->id, 'day' => $day->toDateString(), 'query' => 'עמוד', 'searches' => 5, 'empty' => 5, 'clicks' => 0]);
            foreach (['1.1.1.1', '1.1.1.1', '1.1.1.1', '1.1.1.1', '2.2.2.2'] as $ip) {
                SearchLog::query()->create(['shop_id' => $shop->id, 'query' => 'עמוד', 'results' => 0, 'ip' => $ip, 'created_at' => $day->copy()->addHours(10)]);
            }
        });
        $this->artisan('search:recount', ['--shop' => $shop->slug])->assertSuccessful();
        $this->artisan('search:recount', ['--shop' => $shop->slug])->assertSuccessful();
        app(TenantContext::class)->run((string) $shop->id, function (): void {
            $term = SearchTerm::query()->where('query', 'עמוד')->first();
            $this->assertSame([2, 2], [(int) $term->searches, (int) $term->empty]);
        });

        // the next day it is a search again
        $this->travel(1)->days();
        app(TenantContext::class)->run((string) $shop->id, function () use ($shop): void {
            app(CountSearch::class)->search((string) $shop->id, 'גדרות', 0, ['ip' => '10.0.0.1']);
            $this->assertSame(1, (int) SearchTerm::query()->whereDate('day', now()->toDateString())->where('query', 'גדרות')->value('searches'));
        });
    }
}
