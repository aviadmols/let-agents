<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\WritePageTags;
use App\Modules\Search\Contracts\PageTags;
use App\Modules\Search\Filament\Operator\Pages\ShopSearches;
use App\Modules\Search\Models\SearchPageTags;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\StoredPageTags;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The tag bank: one family writes a page's tags, code keeps those the search can fill, the other
 * family checks each against what it shows, and the page reads them with today's results.
 */
final class PageTagsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{provider: AiProviderName, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        Features::override('search.page_tags', true, $this->shop->id);
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
        Settings::set('search.tags_batch', 10);
        $test = $this;

        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly PageTagsTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 900, 300);
            }
        });

        $this->inShop(function (): void {
            CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'name' => 'ברגים', 'path' => ['ברגים'], 'product_count' => 2, 'url' => 'https://www.store.test/c/1', 'hash' => 'c1']);
            $this->product('201', 'עץ איפאה לדק 21X145');
            $this->product('202', 'בורג נירוסטה לדק 5X50');
            $this->product('203', 'בורג נירוסטה לדק 5X60');
            $this->product('204', 'שמן לעץ חוץ');
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '600', 'title' => 'איך בונים דק',
                'url' => 'https://www.store.test/guide/deck', 'terms' => [], 'hash' => 'h600',
            ]);
        });

        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_one_family_writes_code_keeps_what_the_search_fills_the_other_checks(): void
    {
        $this->replies = [
            ['pages' => [
                ['ref' => 'g1', 'tags' => [
                    ['label' => 'ברגים לדק', 'query' => 'בורג נירוסטה'],
                    ['label' => 'פרגולות מוכנות', 'query' => 'פרגולה'],
                    ['label' => 'שמן לעץ', 'query' => 'שמן'],
                ]],
            ]],
            ['verdicts' => [['id' => 't1', 'verdict' => 'accept', 'reason' => 'מתאים.']]],
        ];
        Settings::set('search.tags_per_run', 1);

        $run = app(WritePageTags::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));
        $this->assertSame('עץ איפאה לדק 21X145', $this->calls[0]['input']['pages'][0]['title'], 'products first');
        $this->assertSame(['ברגים לדק'], array_column($this->calls[1]['input']['items'], 'label'), 'the store sells no pergolas, and one oil is not enough');
        $this->assertSame(['בורג נירוסטה לדק 5X50', 'בורג נירוסטה לדק 5X60'], $this->calls[1]['input']['items'][0]['shows'], 'the checker sees what the tag shows');

        $tags = $this->inShop(fn () => app(PageTags::class)->forPage($this->shop->id, 'product', '201', 4));
        $this->assertSame('ברגים לדק', $tags[0]['label']);
        $this->assertEqualsCanonicalizing(['202', '203'], $tags[0]['products']);

        // The page's words did not change: the next night does not pay for it again.
        $this->calls = [];
        app(WritePageTags::class)->handle($this->shop->id);
        $this->assertNotSame('עץ איפאה לדק 21X145', $this->calls[0]['input']['pages'][0]['title'] ?? null);
    }

    public function test_a_tag_the_team_takes_off_stays_off(): void
    {
        $row = $this->inShop(fn () => SearchPageTags::query()->create([
            'shop_id' => $this->shop->id, 'page_type' => 'product', 'external_id' => '201', 'title' => 'עץ איפאה לדק 21X145',
            'tags' => [['label' => 'ברגים לדק', 'query' => 'בורג נירוסטה']], 'hidden_labels' => [], 'fingerprint' => 'old',
        ]));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(ShopSearches::class, ['shop' => $this->shop->id])
            ->assertSee('תגיות בדפים')
            ->assertSee('ברגים לדק')
            ->call('hideTag', $row->id, 'ברגים לדק');
        $this->assertSame([], $this->inShop(fn () => app(PageTags::class)->forPage($this->shop->id, 'product', '201', 4)));

        // Rewritten with the same label: still off.
        $this->replies = [
            ['pages' => [['ref' => 'g1', 'tags' => [['label' => 'ברגים לדק', 'query' => 'בורג נירוסטה']]]]],
            ['verdicts' => [['id' => 't1', 'verdict' => 'accept']]],
        ];
        Settings::set('search.tags_per_run', 1);
        app(WritePageTags::class)->handle($this->shop->id);
        $this->assertSame([], $this->inShop(fn () => app(PageTags::class)->forPage($this->shop->id, 'product', '201', 4)));
    }

    public function test_a_writer_never_checks_its_own_family(): void
    {
        Settings::set('search.check_provider', 'openai');

        $run = app(WritePageTags::class)->handle($this->shop->id);

        $this->assertSame('failed', $run->status->value);
        $this->assertSame([], $this->calls);
    }

    public function test_choosing_the_tag_bank_is_enough_to_have_tags(): void
    {
        Features::override('search.page_tags', false, $this->shop->id);
        $this->assertFalse(StoredPageTags::wanted($this->shop->id));

        Settings::set('widget.layout', 'tags', $this->shop->id);
        $this->assertTrue(StoredPageTags::wanted($this->shop->id), 'the look of the module turns the tags on, with no second switch');
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    private function product(string $externalId, string $title): CatalogProduct
    {
        return CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish',
            'title' => $title, 'url' => "https://www.store.test/p/{$externalId}", 'image_url' => "https://www.store.test/i/{$externalId}.jpg",
            'price' => '49.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$externalId}", 'payload' => [],
        ]);
    }
}
