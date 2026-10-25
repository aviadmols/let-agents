<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Contracts\TriesQuestions;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\ExplainSearch;
use App\Modules\Search\Filament\Merchant\Pages\SearchCheck;
use App\Modules\Search\Filament\Operator\Pages\SearchCheck as OperatorSearchCheck;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The search check screen: a search run as a shopper's, each result with why it is there, what the store could
 * change, and a question to the AI the whole way a shopper's goes. The same for every store: read from the search's
 * own trace, never a rule for one store or one word.
 */
final class SearchCheckTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => 'lat_'.str_repeat('k', 48)]));
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $books = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'ספרים', 'path' => ['ספרים'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 3]);
            foreach ([
                ['1', 'דרקולה', ['הנמכרים ביותר', 'אימה'], 'https://img.test/1.jpg', true],
                ['2', 'מפגש עם עצמי בגיהינום', ['הנמכרים ביותר'], 'https://img.test/2.jpg', false],
                ['3', 'חשבון לרצח', [], null, true],
            ] as [$id, $title, $tags, $image, $stock]) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/$id", 'image_url' => $image,
                    'price' => '84', 'currency' => 'ILS', 'in_stock' => $stock, 'purchasable' => true, 'hash' => "h$id", 'payload' => ['tags' => $tags]])
                    ->categories()->attach($books->id);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_each_result_says_where_its_words_are_and_nothing_is_counted(): void
    {
        $explained = app(ExplainSearch::class)->search($this->shop->id, 'נמכרים');

        $this->assertSame(['דרקולה', 'מפגש עם עצמי בגיהינום'], array_column($explained['results'], 'title'));
        $first = $explained['results'][0]['ways'][0];
        $this->assertSame(['words_store_tag', 'הנמכרים ביותר'], [$first['key'], $first['params']['where']], 'found by the store\'s own tag, and it says which');
        $this->assertContains('out_of_stock', array_column($explained['suggestions'], 'key'), 'a sold-out result is said');
        $this->assertSame(['1', '2'], $explained['asked_with'], 'the products a question would be asked with');

        $title = app(ExplainSearch::class)->search($this->shop->id, 'דרקולה');
        $this->assertContains('words_title', array_column($title['results'][0]['ways'], 'key'));

        $nothing = app(ExplainSearch::class)->search($this->shop->id, 'חולצה');
        $this->assertSame([], $nothing['results']);
        $this->assertContains('nothing', array_column($nothing['suggestions'], 'key'), 'nothing found: what to add, and where');

        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => SearchTerm::query()->count()), 'a check is no shopper\'s search');
    }

    public function test_a_question_goes_to_the_ai_with_what_the_search_showed_and_the_day_holds_it(): void
    {
        $fake = new class implements TriesQuestions
        {
            /** @var list<array<string, mixed>> */
            public array $asked = [];

            public function trial(string $shopId, string $question, array $products, string $locale): array
            {
                $this->asked[] = compact('question', 'products');

                return ['outcome' => 'no_info', 'answer' => 'אין באתר תשובה.', 'from' => 'model', 'trial' => [
                    'failure' => 'missing_info', 'writer_model' => 'gpt-5.4-mini', 'checker_model' => 'claude-haiku-5-5', 'cost_usd' => 0.007,
                    'looks' => [['question' => $question, 'pages' => [], 'products' => ['דרקולה'], 'chars' => 400]],
                ]];
            }
        };
        $this->app->instance(TriesQuestions::class, $fake);
        Settings::set('search.check_questions_per_day', 1, $this->shop->id);

        $asked = app(ExplainSearch::class)->question($this->shop->id, 'נמכרים', 'he');

        $this->assertSame([['question' => 'נמכרים', 'products' => ['1', '2']]], $fake->asked);
        $this->assertSame(['passed', 'not_code', 'gathered', 'written', 'checked', 'outcome_no_info'], array_column($asked['answer']['steps'], 'key'));
        $this->assertSame(['missing_info'], array_column($asked['answer']['suggestions'], 'key'), 'what to add so it is answered');
        $this->assertSame('gpt-5.4-mini', $asked['answer']['tech']['writer']);

        $this->assertSame(['limit' => true, 'cap' => 1], app(ExplainSearch::class)->question($this->shop->id, 'נמכרים', 'he'), 'the day\'s questions from the screen');
        $this->assertCount(1, $fake->asked);
    }

    public function test_the_store_reads_why_and_what_to_change_and_only_the_operator_sees_the_mechanics(): void
    {
        $owner = User::factory()->create();
        $owner->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Livewire::test(SearchCheck::class)->set('q', 'נמכרים')->call('check')
            ->assertSee('דרקולה')->assertSee('בתגית של החנות')->assertSee('הנמכרים ביותר')
            ->assertSee('מה אפשר לשפר')->assertDontSee('המנגנון');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(OperatorSearchCheck::class, ['shop' => $this->shop->id])->set('q', 'נמכרים')->call('check')
            ->assertSee('דרקולה')->assertSee('המנגנון');
    }
}
