<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AssistantHome;
use App\Modules\Assistant\Support\AssistantReport;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A question the assistant could not answer waits on the shop's home, and on its notices. */
final class AssistantHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_question_waits_on_the_home_with_the_way_to_answer_it(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create(['name' => 'דנה כהן']);
        $merchant->attachShop($shop);

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת', 'url' => 'https://store.test/1', 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
            AssistantAnswer::query()->create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'question' => 'אפשר להחזיר?', 'question_key' => 'return', 'answer' => '',
                'outcome' => AssistantAnswer::NO_INFO, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 4, 'last_asked_at' => now(),
            ]);
        });

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('שאלה אחת של גולשים מחכה לתשובה')
            ->assertSee('״אפשר להחזיר?״ נשאלה 4 פעמים');

        $this->get("/merchant/{$shop->slug}/notices")->assertOk()->assertSee('לענות עכשיו');
    }

    /**
     * The home's "questions shoppers asked" and the analytics screen count the same questions over the same period:
     * the search window's, by when each was asked, and the product pages', by when each was last asked. The list
     * under them is the search window's, and says so.
     */
    public function test_the_home_and_the_analytics_count_the_same_questions(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $ask = fn (string $question, string $outcome, $at) => AssistantSearchAsk::query()->create([
                'shop_id' => $shop->id, 'day' => $at->toDateString(), 'question' => $question, 'products' => [], 'outcome' => $outcome, 'from' => 'model',
            ])->forceFill(['created_at' => $at])->save();
            $ask('משלוח לאילת?', 'answered', now()->subDays(2));
            $ask('משלוח לאילת?', 'answered', now()->subDays(3));
            $ask('יש עגילים מזהב לבן?', 'no_info', now()->subDays(5));
            $ask('מה מזג האוויר?', 'out_of_scope', now()->subDays(6));
            $ask('שאלה ישנה', 'answered', now()->subDays(45));
            $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת', 'url' => 'https://store.test/1', 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
            AssistantAnswer::query()->create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'question' => 'מה המידה?', 'question_key' => 'size', 'answer' => 'מידה 7',
                'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 3, 'last_asked_at' => now()->subDay(),
            ]);
        });

        $this->actingAs($merchant);
        app()->setLocale('en');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);
        app(TenantContext::class)->set($shop->id);
        $since = now()->subDays(30)->startOfDay();

        $card = collect(app(AssistantHome::class)->cards($shop, $since, now(), $since->copy()->subDays(30)))->firstWhere('key', 'questions');
        $figures = app(AssistantReport::class)->figures($shop, $since, now());
        $this->assertSame('5', $card->value, 'four in the search window and one on a product page');
        $this->assertSame(['all' => 5, 'answered' => 3, 'unanswered' => 1, 'off' => 1, 'search' => 4], $figures['questions']);
        $this->assertSame(5, array_sum($figures['series']['questions']), 'the chart counts what the figure counts');
        $this->assertSame(3, app(AssistantReport::class)->rows($shop, 'questions', $since, now(), 10)['total'], 'three different questions in the search window');
        // by count, or by when each was last asked, the latest first (Rocks: questions from before its fixes led the list)
        $rows = fn (string $sort): array => array_map(fn ($r): string => $r->title, app(AssistantReport::class)->rows($shop, 'questions', $since, now(), 10, '', $sort)['rows']);
        $this->assertSame('משלוח לאילת?', $rows('value')[0], 'asked twice');
        $this->assertSame(['משלוח לאילת?', 'יש עגילים מזהב לבן?', 'מה מזג האוויר?'], $rows('date'));
        $this->assertSame('Last asked 18.10.2026 15:00', app(AssistantReport::class)->rows($shop, 'questions', $since, now(), 10, '', 'date')['rows'][0]->sub);

        // the analytics screen, by its address (the Analytics module is not this one's to import)
        $this->withHeader('Accept-Language', 'en')->get("/merchant/{$shop->slug}/analytics")
            ->assertOk()
            ->assertSeeInOrder(['Questions', '5', '3 answered · 1 unanswered · 1 not about the store'])
            ->assertSee('Top customer questions')
            ->assertSee('In the search window: 4');
    }
}
