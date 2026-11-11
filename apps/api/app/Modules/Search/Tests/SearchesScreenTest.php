<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Merchant\Pages\Searches;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchPhotoAsk;
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
            SearchPhotoAsk::query()->create(['shop_id' => $id, 'photo_hash' => hash('sha256', 'ring'), 'thumb' => 'AAAA', 'object' => 'ring', 'tags' => [], 'total' => 9, 'results' => [
                ['external_id' => '11', 'title' => 'טבעת רחבה', 'image' => 'https://shop.test/ring-1.jpg'],
                ['external_id' => '12', 'title' => 'טבעת דקה', 'image' => 'https://shop.test/ring-2.jpg'],
                ['external_id' => '13', 'title' => 'טבעת חותם', 'image' => 'https://shop.test/ring-3.jpg'],
                ['external_id' => '14', 'title' => 'טבעת וינטג׳', 'image' => 'https://shop.test/ring-4.jpg'],
                ['external_id' => '15', 'title' => 'טבעת חמישית', 'image' => 'https://shop.test/ring-5.jpg'],
            ]])->forceFill(['created_at' => now()->subHours(3)])->save();
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
            ->assertSee('לנסות חיפוש')
            // every question opens to its answer and when it was asked; every photo search is one link away
            ->assertSee('התשובה שקיבלו')
            ->assertSee('כן.')
            ->assertSee('נשאלה <bdi>', false)
            ->assertSee('data-all-questions', false)
            ->assertSee('assistant/questions', false)
            ->assertSee('data-all-photos', false)
            ->assertSee('search/photos', false)
            ->assertSee('https://shop.test/ring-3.jpg', false)
            ->assertSee('נמצאו 9 מוצרים');
    }

    public function test_a_question_says_the_product_it_was_asked_about(): void
    {
        // Aviad, 9.11: "here too I want to see from which page or product it was asked"
        $this->inShop(function (): void {
            $product = CatalogProduct::query()->where('external_id', '11')->sole();
            $product->forceFill(['url' => 'https://shop.test/dress'])->save();
            AssistantAnswer::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'question' => 'מאיזה בד היא?', 'question_key' => 'fabric', 'answer' => 'פשתן.', 'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN,
                'source' => 'store', 'prompt_version' => 1, 'asked_count' => 9, 'last_asked_at' => now()->subDay()]);
        });

        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/searches")
            ->assertOk()
            ->assertSeeInOrder(['מאיזה בד היא?', 'נשאל על המוצר:', 'שמלת פשתן לבנה'])
            ->assertSee('https://shop.test/dress', false)
            ->assertSee('https://shop.test/dress.jpg', false);
    }

    public function test_a_question_shows_the_products_its_answer_showed(): void
    {
        // Aviad, 11.11: "add here in the answer that I also see the products that were shown"
        $this->inShop(function (): void {
            $id = $this->shop->id;
            CatalogProduct::query()->create(['shop_id' => $id, 'external_id' => '21', 'hash' => 'h21', 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => 'עגיל חישוק זהב', 'url' => 'https://shop.test/hoop', 'image_url' => 'https://shop.test/hoop.jpg', 'price' => 1250, 'in_stock' => true, 'purchasable' => true]);
            CatalogProduct::query()->create(['shop_id' => $id, 'external_id' => '22', 'hash' => 'h22', 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => 'עגיל טיפה', 'image_url' => 'https://shop.test/drop.jpg', 'in_stock' => true, 'purchasable' => true]);
            $answer = AssistantAnswer::query()->create(['shop_id' => $id, 'question' => 'תראה לי עגיל חישוק', 'question_key' => 'hoop', 'answer' => 'הנה שניים.', 'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN,
                'source' => 'store', 'prompt_version' => 1, 'asked_count' => 20, 'last_asked_at' => now()->subHour()]);
            // an older ask showed something else; the screen shows the latest; the results on screen before the question are not the answer's
            AssistantSearchAsk::query()->create(['shop_id' => $id, 'day' => now()->subDays(2)->toDateString(), 'question' => 'תראה לי עגיל חישוק', 'products' => ['11'], 'outcome' => 'answered', 'from' => 'model', 'answer_id' => $answer->id,
                'picks' => [['external_id' => '11', 'title' => 'שמלת פשתן לבנה', 'why' => '']]])->forceFill(['created_at' => now()->subDays(2)])->save();
            AssistantSearchAsk::query()->create(['shop_id' => $id, 'day' => now()->toDateString(), 'question' => 'תראה לי עגיל חישוק', 'products' => ['11'], 'outcome' => 'answered', 'from' => 'bank', 'answer_id' => $answer->id,
                'picks' => [['external_id' => '21', 'title' => 'עגיל חישוק זהב', 'why' => 'חישוק']], 'shown' => ['22', '21'], 'picked' => ['22']])->forceFill(['created_at' => now()->subHour()])->save();
        });

        $q = collect($this->inShop(fn () => app(SearchScreen::class)->questions($this->shop, now()->subDays(7), now(), 8)))->firstWhere('question', 'תראה לי עגיל חישוק');
        $this->assertSame(['עגיל חישוק זהב', 'עגיל טיפה'], array_column($q['shown'], 'title'), 'its picks first, each once, never what was on screen before');
        $this->assertSame([false, true], array_column($q['shown'], 'opened'));
        $this->assertSame([], collect($this->inShop(fn () => app(SearchScreen::class)->questions($this->shop, now()->subDays(7), now(), 8)))->firstWhere('question', 'יש משלוח לאילת?')['shown']);

        $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/searches")
            ->assertOk()
            ->assertSeeInOrder(['הנה שניים.', 'המוצרים שהוצגו להם', 'עגיל חישוק זהב', 'עגיל טיפה', 'נפתח'])
            ->assertSee('https://shop.test/hoop.jpg', false)
            ->assertSee('https://shop.test/hoop', false);
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
        // the answer the shopper got, and when it was last asked
        $this->assertSame(['כן.', now()->subDay()->getTimestamp()], [$q['answer'], $q['last']]);

        // a photo search: the shopper's photo, the first four it showed, how many in all
        $photo = $this->inShop(fn () => $screen->photos($this->shop, $since, now(), 4))[0];
        $this->assertSame(['טבעת רחבה', 9, 4], [$photo['title'], $photo['total'], count($photo['found'])]);
        $this->assertSame('https://shop.test/ring-4.jpg', $photo['found'][3]['picture']);
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
