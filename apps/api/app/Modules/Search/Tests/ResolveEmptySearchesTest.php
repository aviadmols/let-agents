<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\ResolveEmptySearches;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Filament\Operator\Pages\ShopSearches;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A search that found nothing, answered at night: code finds what is near it by meaning, one
 * family matches, the other checks, and from then on that search shows what both accepted.
 */
final class ResolveEmptySearchesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{provider: AiProviderName, model: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> model replies, in order */
    public array $replies = [];

    /** @var list<array<string, mixed>> what the fake index by meaning answers */
    public array $meaning = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        $test = $this;

        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly ResolveEmptySearchesTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 800, 120);
            }
        });

        $this->app->instance(SemanticSearch::class, new class($test) implements SemanticSearch
        {
            public function __construct(private readonly ResolveEmptySearchesTest $test) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                return [];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return $this->test->meaning;
            }

            public function lookAlike(string $productId, int $limit): array
            {
                return [];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return [];
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return false;
            }

            public function ready(): bool
            {
                return true;
            }
        });

        $this->inShop(function (): void {
            CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'name' => 'עצים', 'path' => ['עצים'], 'product_count' => 40, 'url' => 'https://www.store.test/c/1', 'hash' => 'c1']);
            $this->product('101', 'בורג נירוסטה לדק 5X50');
            $this->product('102', 'עץ אורן מוקצע 42X92', ['in_stock' => false]);
            $this->product('103', 'עץ איפאה לדק 21X145');
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '500', 'title' => 'איך בונים דק',
                'url' => 'https://www.store.test/guide/deck', 'terms' => [], 'hash' => 'h500',
            ]);
            SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->subDay()->toDateString(), 'query' => 'קרשים', 'searches' => 5, 'empty' => 5]);
            SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->subDay()->toDateString(), 'query' => 'פעם אחת', 'searches' => 1, 'empty' => 1]);
        });

        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->meaning = [
            $this->hit('product', '101', 'בורג נירוסטה לדק 5X50'),
            $this->hit('product', '102', 'עץ אורן מוקצע 42X92'),
            $this->hit('product', '103', 'עץ איפאה לדק 21X145'),
            $this->hit('content', '500', 'איך בונים דק'),
        ];
    }

    public function test_one_family_matches_the_other_checks_and_the_search_shows_it_from_then_on(): void
    {
        $this->replies = [
            ['matches' => ['c2', 'c3', 'c9'], 'synonym' => 'עץ', 'reason' => 'קרשים הם עץ.'],
            ['verdicts' => [['id' => 'r1', 'verdict' => 'accept', 'reason' => 'מתאים.']]],
        ];

        $run = app(ResolveEmptySearches::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertCount(2, $this->calls, 'one search asked: once to match, once to check');
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));
        $this->assertSame(HebrewSearch::normalize('קרשים'), $this->calls[0]['input']['query'], 'searched once is not enough to be sent');
        $this->assertSame(['בורג נירוסטה לדק 5X50', 'עץ איפאה לדק 21X145', 'איך בונים דק'], array_column($this->calls[0]['input']['candidates'], 'title'), 'what was out of stock last night is not offered');
        $this->assertSame(['עץ איפאה לדק 21X145', 'איך בונים דק'], $this->calls[1]['input']['items'][0]['matches'], 'a ref code never offered is dropped');

        $resolution = $this->inShop(fn () => SearchResolution::query()->sole());
        $this->assertSame([SearchResolution::RESOLVED, ['103'], ['500'], 'עץ'], [$resolution->status, $resolution->products, $resolution->content, $resolution->synonym_means]);
        $this->assertSame([HebrewSearch::normalize('קרשים'), 'עץ', 'resolution'], array_values($this->inShop(fn () => SearchSynonym::query()->sole()->only(['term', 'means', 'origin']))));
        $this->assertGreaterThan(0, (float) $run->cost_usd);

        $result = app(SearchCatalog::class)->handle($this->shop->id, 'קרשים', count: false);
        $this->assertTrue($result['resolved']);
        $this->assertSame('עץ איפאה לדק 21X145', $result['groups']['product'][0]['title'], 'what was resolved comes first');
        $this->assertSame('איך בונים דק', $result['groups']['content'][0]['title']);

        // Settled: the next night does not ask again.
        $this->calls = [];
        app(ResolveEmptySearches::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
    }

    public function test_what_the_checker_refuses_never_reaches_the_search(): void
    {
        $this->replies = [
            ['matches' => ['c1'], 'synonym' => null, 'reason' => 'ברגים.'],
            ['verdicts' => [['id' => 'r1', 'verdict' => 'refuse', 'reason' => 'ברגים הם לא קרשים.']]],
        ];

        app(ResolveEmptySearches::class)->handle($this->shop->id);

        $this->assertSame(SearchResolution::REFUSED, $this->inShop(fn () => SearchResolution::query()->value('status')));
        $this->assertFalse(app(SearchCatalog::class)->handle($this->shop->id, 'קרשים', count: false)['resolved']);
    }

    public function test_nothing_near_it_costs_no_model_and_is_kept_for_the_suggestions(): void
    {
        $this->meaning = [];

        $run = app(ResolveEmptySearches::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame([], $this->calls);
        $this->assertSame(SearchResolution::NONE, $this->inShop(fn () => SearchResolution::query()->value('status')));
    }

    public function test_a_matcher_never_checks_its_own_family(): void
    {
        Settings::set('search.check_provider', 'openai');

        $run = app(ResolveEmptySearches::class)->handle($this->shop->id);

        $this->assertSame('failed', $run->status->value);
        $this->assertSame([], $this->calls, 'stopped before any model was asked');
    }

    public function test_the_team_can_take_a_resolution_back_and_restore_it(): void
    {
        $this->replies = [
            ['matches' => ['c2'], 'synonym' => 'עץ', 'reason' => 'עץ.'],
            ['verdicts' => [['id' => 'r1', 'verdict' => 'accept', 'reason' => 'מתאים.']]],
        ];
        app(ResolveEmptySearches::class)->handle($this->shop->id);
        $id = $this->inShop(fn () => SearchResolution::query()->value('id'));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $page = Livewire::test(ShopSearches::class, ['shop' => $this->shop->id])
            ->assertSee('חיפושים שהמערכת פתרה')
            ->assertSee('עץ איפאה לדק 21X145')
            ->call('undoResolution', $id);

        $this->assertSame(SearchResolution::REJECTED, $this->inShop(fn () => SearchResolution::query()->value('status')));
        $this->assertSame(0, $this->inShop(fn () => SearchSynonym::query()->count()), 'its synonym goes with it');
        $this->assertFalse(app(SearchCatalog::class)->handle($this->shop->id, 'קרשים', count: false)['resolved']);

        $page->call('restoreResolution', $id);
        $this->assertSame(SearchResolution::RESOLVED, $this->inShop(fn () => SearchResolution::query()->value('status')));
        $this->assertSame(1, $this->inShop(fn () => SearchSynonym::query()->count()));
    }

    /** @return array<string, mixed> */
    private function hit(string $source, string $externalId, string $title): array
    {
        return ['source' => $source, 'source_id' => $externalId, 'external_id' => $externalId, 'title' => $title, 'similarity' => 0.6];
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** @param array<string, mixed> $overrides */
    private function product(string $externalId, string $title, array $overrides = []): CatalogProduct
    {
        return CatalogProduct::query()->create(array_replace([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish',
            'title' => $title, 'url' => "https://www.store.test/p/{$externalId}", 'image_url' => "https://www.store.test/i/{$externalId}.jpg",
            'price' => '49.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$externalId}", 'payload' => [],
        ], $overrides));
    }
}
