<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Search\Actions\ArrangeFilters;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Contracts\FilterLayouts;
use App\Modules\Search\Models\SearchFilterLayout;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\FilterLayoutGate;
use App\Modules\Search\Support\FilterScreen;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The filter layout (19.11): a store's filter sheet had two filters for one thing, a "Category" that was a heap of kinds
 * and places, and values in no sensible order. An advanced model arranges the screen, a model of the other family
 * scores every change and can only veto, and code applies only an order and hiding: nothing it does not find on the
 * screen, never fewer filters than the minimum, and no filter code moves.
 */
final class FilterLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{role: string, provider: string, model: string, schema: bool, effort: ?string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> what the writer answers */
    public array $written = [];

    /** @var array<string, array{0: int, 1: string}|null> the checker's answer for an item id; null leaves it unscored. Others get 90, agree. */
    public array $scores = [];

    /** @var list<array<string, mixed>> answers the checker adds of its own */
    public array $extra = [];

    public int $capAsked = 0;

    public bool $capReached = false;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'en']);
        Features::override(ArrangeFilters::FEATURE, true, $this->shop->id);
        $this->written = self::reply();

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel, StructuredChatModel
        {
            public function __construct(private readonly FilterLayoutTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user, false, $reasoningEffort);
            }

            public function structured(AiProviderName $provider, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user, $schema !== [], $reasoningEffort);
            }

            private function answer(AiProviderName $provider, string $model, string $system, string $user, bool $schema, ?string $effort): ModelReply
            {
                $input = (array) json_decode($user, true);
                $role = str_contains($system, 'You arrange the filter screen') ? 'writer' : 'checker';
                $this->test->calls[] = ['role' => $role, 'provider' => $provider->value, 'model' => $model, 'schema' => $schema, 'effort' => $effort, 'input' => $input];
                if ($role === 'writer') {
                    return new ModelReply($this->test->written, 9000, 2000);
                }
                $items = [];
                foreach ((array) $input['items'] as $item) {
                    if (array_key_exists($item['id'], $this->test->scores) && $this->test->scores[$item['id']] === null) {
                        continue;
                    }
                    [$score, $verdict] = $this->test->scores[$item['id']] ?? [90, 'agree'];
                    $items[] = ['id' => $item['id'], 'score' => $score, 'verdict' => $verdict, 'reason' => 'as the counts show ('.$item['id'].')'];
                }

                return new ModelReply(['items' => [...$items, ...$this->test->extra]], 7000, 1500);
            }
        });
        $this->app->instance(SpendGuard::class, new class($test) implements SpendGuard
        {
            public function __construct(private readonly FilterLayoutTest $test) {}

            public function assertCanSpend(float $estimatedUsd, bool $forShopper = false): void
            {
                $this->test->capAsked++;
                if ($this->test->capReached) {
                    throw new SpendCapReached(30.0, $estimatedUsd, 30.0);
                }
            }

            public function spentThisMonth(): float
            {
                return 0.0;
            }

            public function cap(): float
            {
                return 100.0;
            }

            public function spentThisMonthFor(string $shopId): float
            {
                return 0.0;
            }

            public function capFor(string $shopId): float
            {
                return 30.0;
            }
        });

        $this->inShop(fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => 'h', 'items' => json_encode(self::index(), JSON_UNESCAPED_UNICODE), 'counts' => [], 'built_at' => now()]));
    }

    /**
     * A store's index as the drawer gets it: a category that mixes kinds and places, a tag-bank destination that holds
     * the places, a tag-bank duration beside the store's own length (the same split of the same products), brands and
     * letter sizes stored large to small.
     *
     * @return array<string, mixed>
     */
    private static function index(): array
    {
        $facets = [
            ['k' => 'category', 'l' => 'Category', 'v' => [['k' => '11', 'l' => 'Hiking'], ['k' => '12', 'l' => 'Italy'], ['k' => '13', 'l' => 'France'], ['k' => '14', 'l' => 'Cycling']]],
            ['k' => 'tag:destination', 'l' => 'Destination', 'v' => [['k' => 'italy', 'l' => 'Italy'], ['k' => 'france', 'l' => 'France'], ['k' => 'spain', 'l' => 'Spain']]],
            ['k' => 'tag:duration', 'l' => 'Duration', 'v' => [['k' => 'short', 'l' => 'Short'], ['k' => 'long', 'l' => 'Long']]],
            ['k' => 'length', 'l' => 'Length', 'v' => [['k' => '2-5', 'l' => '2 to 5 days'], ['k' => '6-9', 'l' => '6 to 9 days']]],
            ['k' => 'brand', 'l' => 'Brand', 'v' => [['k' => 'brand:a', 'l' => 'Alpha'], ['k' => 'brand:b', 'l' => 'Beta']]],
            ['k' => 'variant_size', 'l' => 'Size', 'v' => [['k' => 'L', 'l' => 'L'], ['k' => 'S', 'l' => 'S'], ['k' => 'M', 'l' => 'M']]],
        ];
        $codes = [
            1 => [0, 1, 1000, 2000, 3000, 4000, 5001], 2 => [0, 1, 1000, 2000, 3000, 4001, 5002], 3 => [0, 2, 1001, 2001, 3001, 4000, 5000],
            4 => [3, 2, 1001, 2001, 3001, 4001, 5001], 5 => [3, 1, 1000, 2000, 3000, 4000, 5002], 6 => [0, 1002, 2001, 3001, 4001, 5001],
            7 => [3, 1002, 2000, 3000, 4000, 5001], 8 => [0, 2, 1001, 2001, 3001, 4001, 5000],
        ];
        $items = [];
        foreach ($codes as $id => $f) {
            $items[] = ['id' => 'p:'.$id, 't' => 'product', 'title' => 'Trip '.$id, 'kw' => '', 'f' => $f];
        }
        $items[] = ['id' => 'c:1', 't' => 'content', 'title' => 'A guide', 'f' => [1000]];

        return ['v' => 1, 'items' => $items, 'facets' => $facets];
    }

    /** @return array<string, mixed> a writer's reply that changes nothing */
    private static function reply(array $with = []): array
    {
        return $with + ['filter_order' => [], 'filter_order_why' => '', 'value_orders' => [], 'hides' => [], 'splits' => [], 'notes' => []];
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    private function arrange(bool $force = false): array
    {
        return app(ArrangeFilters::class)->handle($this->shop->id, $force);
    }

    public function test_the_screen_is_read_from_the_index_with_counts_and_what_filters_and_values_share(): void
    {
        $screen = $this->inShop(fn () => ArrangeFilters::screenOf($this->shop->id));

        $this->assertSame(['category', 'tag:destination', 'tag:duration', 'length', 'brand', 'variant_size'], $screen->keys());
        $this->assertSame(8, $screen->products, 'products only: a guide is no product');
        $this->assertSame(['category', 'tag_bank', 'tag_bank', 'length', 'brand', 'variant'], array_column($screen->filters, 'source'));
        $this->assertSame(['Hiking', 'Italy', 'France', 'Cycling'], array_column($screen->filter('category')['values'], 'label'), 'the most products first');
        $this->assertSame([5, 3, 3, 3], array_column($screen->filter('category')['values'], 'products'));
        $this->assertSame(['L', 'S', 'M'], $screen->valueKeysNow('variant_size'), 'a size filter of letters keeps the store\'s order today');
        $this->assertSame(1.0, $screen->shared('tag:duration', 'length'));
        $pair = collect($screen->filterOverlaps)->first(fn (array $p): bool => $p['a'] === 'tag:duration' && $p['b'] === 'length');
        $this->assertSame(1.0, $pair['same_split'], 'the two split the same products the same way');
        $this->assertSame(1.0, $screen->cover('category', '12', 'tag:destination', 'italy'));
        $this->assertSame(0.4, $screen->cover('category', '11', 'tag:destination', 'italy'));
        $same = collect($screen->valueOverlaps)->first(fn (array $p): bool => $p['a'] === ['filter' => 'category', 'value' => '12'] && $p['b'] === ['filter' => 'tag:destination', 'value' => 'italy']);
        $this->assertNotNull($same);
        $this->assertTrue($same['same_label']);
        $this->assertSame(3, $same['both']);

        // a tag filter of day ranges beside the store's own length filter is not on the screen, as the drawer shows none
        $index = self::index();
        $index['facets'][2]['v'] = [['k' => 'a', 'l' => '1–3 days'], ['k' => 'b', 'l' => '4+ days']];
        $this->assertNotContains('tag:duration', (new FilterScreen($index))->keys());
    }

    public function test_a_duplicate_filter_is_hidden_only_when_the_checker_agrees(): void
    {
        $this->written = self::reply(['hides' => [['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => 'same split as Length']]]);
        $this->scores = ['h1' => [88, 'agree']];

        ['run' => $run, 'layout' => $layout] = $this->arrange();

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(SearchFilterLayout::APPLIED, $layout->status);
        $this->assertSame(['tag:duration' => 'duplicate_of:length'], $layout->applied['hide']);
        $this->assertSame(['tag:duration' => 'duplicate_of:length'], app(FilterLayouts::class)->applied($this->shop->id)['hide']);

        // the checker vetoes: the filter stays, and the newest layout is the one the index reads
        $this->scores = ['h1' => [95, 'veto']];
        ['layout' => $kept] = $this->arrange(true);
        $this->assertSame(SearchFilterLayout::KEPT, $kept->status);
        $this->assertSame([], $kept->applied['hide']);
        $this->assertSame('vetoed', $kept->review['verdicts']['h1']['why']);

        // under the threshold, or unscored: not applied either
        $this->scores = ['h1' => [69, 'agree']];
        $this->assertSame([], $this->arrange(true)['layout']->applied['hide']);
        $this->scores = ['h1' => null];
        $this->assertSame('unscored', $this->arrange(true)['layout']->review['verdicts']['h1']['why']);

        // brands and destinations are on every product, but split them otherwise: no duplicate, and code ignores it before
        // the checker sees it
        $screen = $this->inShop(fn () => ArrangeFilters::screenOf($this->shop->id));
        $this->assertSame([1.0, 0.63], [$screen->shared('brand', 'tag:destination'), $screen->sameSplit('brand', 'tag:destination')]);
        $this->written = self::reply(['hides' => [['filter' => 'brand', 'reason' => 'duplicate', 'duplicate_of' => 'tag:destination', 'why' => '']]]);
        $this->calls = [];
        $layout = $this->arrange(true)['layout'];
        $this->assertSame([], $layout->items);
        $this->assertSame('no_overlap', $layout->refused['ignored'][0]['why']);
        $this->assertSame(['writer'], array_column($this->calls, 'role'), 'nothing to score, no checker asked');
    }

    public function test_an_invented_key_or_label_is_ignored(): void
    {
        $this->written = self::reply([
            'filter_order' => ['colour', 'brand', 'category', 'brand'],
            'value_orders' => [['filter' => 'variant_size', 'values' => ['S', 'XL', 'M', 'L'], 'why' => 'small to large'], ['filter' => 'Material', 'values' => ['wood', 'metal'], 'why' => '']],
            'hides' => [['filter' => 'made_up', 'reason' => 'says_nothing', 'duplicate_of' => '', 'why' => ''], ['filter' => 'brand', 'reason' => 'duplicate', 'duplicate_of' => 'Brands', 'why' => '']],
            'splits' => [['filter' => 'category', 'moves' => [['value' => '12', 'into' => 'tag:destination', 'as' => 'Italia'], ['value' => 'Italy', 'into' => 'tag:destination', 'as' => 'italy'], ['value' => '13', 'into' => 'tag:region', 'as' => 'france']], 'why' => '']],
        ]);

        ['layout' => $layout] = $this->arrange();

        // what reaches the checker is only what the screen has
        $checked = $this->calls[1]['input']['items'];
        $this->assertSame(['o1', 'v1'], array_column($checked, 'id'));
        $this->assertSame(['brand', 'category', 'tag:destination', 'tag:duration', 'length', 'variant_size'], $checked[0]['order'], 'an unknown key dropped, a repeated one once, the rest after in today\'s order');
        $this->assertSame(['S', 'M', 'L'], $checked[1]['values'], 'a value the filter has not is dropped');
        $this->assertEqualsCanonicalizing(
            ['order:colour:unknown_filter', 'values:XL:unknown_value', 'values::unknown_filter', 'hide::unknown_filter', 'hide:Brands:unknown_duplicate', 'move:12 > tag:destination.Italia:unknown_value', 'move:Italy > tag:destination.italy:unknown_value', 'move:13 > tag:region.france:unknown_filter'],
            array_map(fn (array $i): string => $i['what'].':'.$i['key'].':'.$i['why'], $layout->refused['ignored']),
        );

        // and the checker cannot add: an id code did not give is ignored
        $this->extra = [['id' => 'h9', 'score' => 100, 'verdict' => 'agree', 'reason' => 'hide brand too']];
        $again = $this->arrange(true)['layout'];
        $this->assertSame(['h9'], $again->review['ignored']);
        $this->assertSame([], $again->applied['hide']);
        $this->assertSame(['brand', 'category', 'tag:destination', 'tag:duration', 'length', 'variant_size'], $again->applied['order']);
        $this->assertSame(['variant_size' => [['S', 'S'], ['M', 'M'], ['L', 'L']]], $again->applied['values']);
    }

    public function test_a_hide_that_would_leave_fewer_than_the_minimum_filters_is_refused(): void
    {
        Settings::set('search.filter_layout_min_filters', 5, $this->shop->id);
        $this->written = self::reply(['hides' => [
            ['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => ''],
            ['filter' => 'brand', 'reason' => 'says_nothing', 'duplicate_of' => '', 'why' => ''],
        ]]);
        $this->scores = ['h1' => [80, 'agree'], 'h2' => [95, 'agree']];

        ['layout' => $layout] = $this->arrange();

        $this->assertSame(['brand' => 'says_nothing'], $layout->applied['hide'], 'the surer one goes; six filters, five stay');
        $this->assertSame([['id' => 'h1', 'why' => 'too_few_filters']], $layout->refused['refused']);
        $this->assertSame(5, $this->artisanMinimum(), 'a bounded setting');
    }

    private function artisanMinimum(): int
    {
        return (int) Settings::get('search.filter_layout_min_filters', $this->shop->id);
    }

    public function test_two_duplicates_of_each_other_never_both_go(): void
    {
        $this->written = self::reply(['hides' => [
            ['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => ''],
            ['filter' => 'length', 'reason' => 'duplicate', 'duplicate_of' => 'tag:duration', 'why' => ''],
        ]]);
        $this->scores = ['h1' => [92, 'agree'], 'h2' => [85, 'agree']];

        $layout = $this->arrange()['layout'];

        $this->assertSame(['tag:duration' => 'duplicate_of:length'], $layout->applied['hide']);
        $this->assertSame([['id' => 'h2', 'why' => 'kept_for_duplicate']], $layout->refused['refused']);
    }

    public function test_a_mixed_filter_gives_its_places_to_the_filter_that_holds_them(): void
    {
        $this->written = self::reply(['splits' => [['filter' => 'category', 'moves' => [
            ['value' => '12', 'into' => 'tag:destination', 'as' => 'italy'],
            ['value' => '13', 'into' => 'tag:destination', 'as' => 'france'],
            // most hikes are not in Italy: the move has no products under it, and code ignores it
            ['value' => '11', 'into' => 'tag:destination', 'as' => 'italy'],
        ], 'why' => 'places beside kinds']]]);

        $layout = $this->arrange()['layout'];

        $this->assertSame(['category' => [['12', 'Italy', 'tag:destination', 'italy'], ['13', 'France', 'tag:destination', 'france']]], $layout->applied['moves']);
        $this->assertSame('no_overlap', $layout->refused['ignored'][0]['why']);
        $this->assertEquals([1, 1], array_column($this->calls[1]['input']['items'], 'cover'));

        // a filter keeps two values at least
        $this->written = self::reply(['splits' => [['filter' => 'tag:duration', 'moves' => [['value' => 'short', 'into' => 'length', 'as' => '2-5']], 'why' => '']]]);
        $layout = $this->arrange(true)['layout'];
        $this->assertSame([['id' => 'm1', 'why' => 'too_few_values']], $layout->refused['refused']);
        $this->assertSame([], $layout->applied['moves']);
    }

    public function test_writer_and_checker_of_one_family_fail_before_any_call(): void
    {
        Settings::set('search.filter_layout_checker_provider', 'anthropic');

        ['run' => $run, 'layout' => $layout] = $this->arrange();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('search::runs.layout_same_family', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->capAsked);
        $this->assertNull($layout);
        $this->assertSame(0, $this->inShop(fn () => SearchFilterLayout::query()->count()));
    }

    public function test_by_default_an_anthropic_model_arranges_and_an_openai_model_checks_each_held_to_its_schema_and_every_cost_is_recorded(): void
    {
        $this->written = self::reply(['hides' => [['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => '']]]);

        ['run' => $run, 'layout' => $layout] = $this->arrange();

        $this->assertSame(['anthropic', 'openai'], array_column($this->calls, 'provider'));
        $this->assertSame(['claude-opus-5-5', 'gpt-5.4-mini'], array_column($this->calls, 'model'));
        $this->assertSame([true, true], array_column($this->calls, 'schema'), 'structured output on every call');
        $this->assertSame(2, $this->capAsked, 'the cap is asked before each call');
        $this->assertSame(['filter_layout.v1', 'filter_layout_check.v1'], array_column($layout->calls, 'prompt'));
        // the writer at $4 and $20 a million, the checker at $1 and $8
        $this->assertSame([0.076, 0.019], array_column($layout->calls, 'usd'));
        $this->assertSame(16000, $run->input_tokens);
        $this->assertSame(3500, $run->output_tokens);
        $this->assertEqualsWithDelta(0.095, (float) $run->cost_usd, 0.0001);
        $this->assertSame(16000, $layout->input_tokens);
        $this->assertEqualsWithDelta(0.095, (float) $layout->cost_usd, 0.0001);
        $this->assertCount(5, $layout->steps);
        $this->assertStringContainsString('המסך: הקוד קרא 6 מסננים', $layout->steps[0]);
        $this->assertStringContainsString('הצעה (claude-opus-5-5)', $layout->steps[1]);
        $this->assertStringContainsString('בדיקה (gpt-5.4-mini)', $layout->steps[2]);
        $this->assertStringContainsString('שער הקוד', $layout->steps[3]);
        $this->assertSame((string) $run->summary(), $layout->steps[4]);
    }

    public function test_a_reply_that_breaks_its_schema_fails_the_run_and_is_not_mended(): void
    {
        $this->written = ['filter_order' => [], 'value_orders' => [], 'hides' => [['filter' => 'brand', 'reason' => 'boring', 'duplicate_of' => '', 'why' => '']], 'splits' => [], 'notes' => []];

        ['run' => $run, 'layout' => $layout] = $this->arrange();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('search::runs.layout_schema', $run->summary_key);
        $this->assertSame(['writer'], array_column($this->calls, 'role'), 'not asked again, and the checker never asked');
        $this->assertSame(SearchFilterLayout::FAILED, $layout->status);
        $this->assertNull($layout->applied);
        $this->assertNotEmpty($layout->problems);
        $this->assertSame(9000, $run->input_tokens, 'what the broken reply cost is still on the run');
        $this->assertNull(app(FilterLayouts::class)->applied($this->shop->id), 'a failed run is never read by the index');
    }

    public function test_the_cap_is_asked_first_and_a_cap_reached_asks_no_model(): void
    {
        $this->capReached = true;

        ['run' => $run, 'layout' => $layout] = $this->arrange();

        $this->assertSame('search::runs.layout_spend_cap', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(SearchFilterLayout::FAILED, $layout->status);
    }

    public function test_the_same_screen_is_not_asked_twice_unless_forced(): void
    {
        $this->written = self::reply(['hides' => [['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => '']]]);
        $first = $this->arrange()['layout'];
        $this->assertCount(2, $this->calls);

        ['run' => $run, 'layout' => $again] = $this->arrange();
        $this->assertCount(2, $this->calls, 'nothing asked');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('search::runs.layout_unchanged', $run->summary_key);

        $this->arrange(true);
        $this->assertCount(4, $this->calls);
    }

    public function test_the_flag_is_off_by_default_and_nothing_runs_or_applies_without_it(): void
    {
        $this->written = self::reply(['hides' => [['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => '']]]);
        $this->arrange();
        $this->assertNotNull(app(FilterLayouts::class)->applied($this->shop->id));

        Features::override(ArrangeFilters::FEATURE, false, $this->shop->id);
        $other = Shop::factory()->create();
        $this->assertFalse(Features::enabled(ArrangeFilters::FEATURE, $other->id), 'off unless turned on');
        $this->assertNull(app(FilterLayouts::class)->applied($this->shop->id), 'a stored layout is not applied with the flag off');

        $this->calls = [];
        ['run' => $run] = $this->arrange(true);
        $this->assertSame('search::runs.layout_off', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug])->expectsOutput('סידור המסננים כבוי לחנות הזאת.')->assertSuccessful();
        $this->assertSame([], $this->calls);
    }

    public function test_the_index_applies_the_layout_without_moving_a_code_and_without_one_is_as_before(): void
    {
        $this->catalogue();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $before = $this->built();
        $facets = $before['facets'];
        $category = array_search('category', array_column($facets, 'k'), true);
        $deal = array_search('deal', array_column($facets, 'k'), true);
        $this->assertNotFalse($category);
        $this->assertNotFalse($deal);
        $values = $facets[$category]['v'];
        $byLabel = array_column($values, 'k', 'l');

        // a layout the index reads: deal first, the category's values in an order, one of them moved, and a hide of a
        // filter whose key is gone and of a value whose label changed, passed over
        $this->inShop(fn () => SearchFilterLayout::query()->create(['shop_id' => $this->shop->id, 'status' => SearchFilterLayout::APPLIED, 'fingerprint' => 'x', 'applied' => [
            'order' => ['deal', 'category'],
            'values' => ['category' => [[$byLabel['Rings'], 'Rings'], [$byLabel['Earrings'], 'Earrings'], [$byLabel['Necklaces'], 'Necklaces (old name)']]],
            'hide' => ['tag:gone' => 'says_nothing'],
            'moves' => ['category' => [[$byLabel['Necklaces'], 'Necklaces', 'deal', 'x']]],
        ]]));
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $after = $this->built();

        $this->assertSame(array_column($before['items'], 'f', 'id'), array_column($after['items'], 'f', 'id'), 'no product\'s codes move');
        $this->assertSame(array_column($facets, 'k'), array_column($after['facets'], 'k'), 'no filter moves in the list');
        $laid = $after['facets'][$category];
        $this->assertSame($values, $laid['v'], 'nothing renamed or added');
        $this->assertSame(1, $laid['o']);
        $this->assertSame(0, $after['facets'][$deal]['o']);
        $this->assertSame([array_search('Rings', array_column($values, 'l'), true), array_search('Earrings', array_column($values, 'l'), true)], $laid['vo'], 'a value whose label changed is passed over');
        $this->assertSame([array_search('Necklaces', array_column($values, 'l'), true)], $laid['vh']);
        $this->assertArrayNotHasKey('hide', $laid);

        // a hide marks the filter, and keeps its codes
        $this->inShop(fn () => SearchFilterLayout::query()->update(['applied' => json_encode(['order' => [], 'values' => [], 'hide' => ['deal' => 'says_nothing'], 'moves' => []])]));
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $hidden = $this->built();
        $this->assertSame(1, $hidden['facets'][$deal]['hide']);
        $this->assertSame(array_column($before['items'], 'f', 'id'), array_column($hidden['items'], 'f', 'id'));

        // the flag off, or no layout: the index exactly as before
        Features::override(ArrangeFilters::FEATURE, false, $this->shop->id);
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->assertSame($before, $this->built());
        Features::override(ArrangeFilters::FEATURE, true, $this->shop->id);
        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug, '--clear' => true])->assertSuccessful();
        $this->assertSame(0, $this->inShop(fn () => SearchFilterLayout::query()->count()));
        $this->assertSame($before, $this->built(), 'cleared, the index is built again as before');
    }

    public function test_the_command_prints_the_screen_before_and_after_with_every_score_and_shows_and_clears(): void
    {
        $this->written = self::reply([
            'filter_order' => ['length', 'tag:destination', 'category'],
            'value_orders' => [['filter' => 'variant_size', 'values' => ['S', 'M', 'L'], 'why' => 'small to large']],
            'hides' => [
                ['filter' => 'tag:duration', 'reason' => 'duplicate', 'duplicate_of' => 'length', 'why' => ''],
                ['filter' => 'brand', 'reason' => 'says_nothing', 'duplicate_of' => '', 'why' => ''],
            ],
            'splits' => [['filter' => 'category', 'moves' => [['value' => '12', 'into' => 'tag:destination', 'as' => 'italy']], 'why' => '']],
            'notes' => ['"Category" mixes trip kinds and countries.'],
        ]);
        $this->scores = ['h2' => [60, 'agree']];

        // every line expected is said once: what moved shows how it moved
        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug])
            ->expectsOutputToContain('המסך: הקוד קרא 6 מסננים')
            ->expectsOutput('לפני:')
            ->expectsOutput('1. Category (8 מוצרים)')
            ->expectsOutput('     Hiking (5), Italy (3), France (3), Cycling (3)')
            ->expectsOutput('     L (2), S (4), M (2)')
            ->expectsOutput('אחרי:')
            ->expectsOutput('1. Length (8 מוצרים)')
            ->expectsOutput('3. Category (8 מוצרים)')
            ->expectsOutput('     Hiking (5), France (3), Cycling (3)')
            ->expectsOutput('     S (4), M (2), L (2)')
            ->expectsOutput('מוסתר: Duration (כפול של Length)')
            ->expectsOutput('  [סדר המסננים] Length › Destination › Category › Duration › Brand › Size')
            ->expectsOutput('      ציון 90, הוחל. as the counts show (o1)')
            ->expectsOutput('  [סדר ערכים] Size: S › M › L')
            ->expectsOutput('      ציון 90, הוחל. as the counts show (v1)')
            ->expectsOutput('      הכותב: small to large')
            ->expectsOutput('  [הסתרה] Duration (כפול של Length)')
            ->expectsOutput('      ציון 90, הוחל. as the counts show (h1)')
            ->expectsOutput('  [הסתרה] Brand (לא אומר לגולש כלום)')
            ->expectsOutput('      ציון 60, ציון נמוך מהסף, לא הוחל. as the counts show (h2)')
            ->expectsOutput('  [העברת ערך] Category › Italy → Destination › Italy (100%)')
            ->expectsOutput('      ציון 90, הוחל. as the counts show (m1)')
            ->expectsOutput('הערה של הכותב: "Category" mixes trip kinds and countries.')
            ->expectsOutput('אינדקס החיפוש נבנה מחדש, והמגירה מקבלת את המסך הזה.')
            ->assertSuccessful();
        $this->assertCount(2, $this->calls);

        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug, '--show' => true])
            ->expectsOutputToContain('search_filter_layouts')
            ->expectsOutput('מוסתר: Duration (כפול של Length)')
            ->assertSuccessful();
        $this->assertCount(2, $this->calls, 'showing asks no model');

        $this->artisan('search', ['step' => 'layout', '--all' => true])->assertFailed();
        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug, '--clear' => true])
            ->expectsOutputToContain('סידור המסננים נמחק (1 רשומות)')->assertSuccessful();
        $this->assertSame(0, $this->inShop(fn () => SearchFilterLayout::query()->count()));
        $this->artisan('search', ['step' => 'layout', 'target' => $this->shop->slug, '--show' => true])->assertFailed();
        $this->assertCount(2, $this->calls, 'never for every shop, and clearing asks nothing');
    }

    public function test_the_gate_reads_the_checker_strictly(): void
    {
        $ids = ['h1', 'v1'];
        [$verdicts, $ignored] = FilterLayoutGate::verdicts($ids, [
            ['id' => 'h1', 'score' => 95, 'verdict' => 'agree', 'reason' => ''],
            ['id' => 'h1', 'score' => 90, 'verdict' => 'veto', 'reason' => 'second thoughts'],
            ['id' => 'v1', 'score' => 70.5, 'verdict' => 'agree', 'reason' => ''],
            ['id' => 'x', 'score' => 100, 'verdict' => 'agree', 'reason' => ''],
        ], 70);

        $this->assertSame('vetoed', $verdicts['h1']['why'], 'of two answers the harsher counts');
        $this->assertSame('unscored', $verdicts['v1']['why'], 'a score is a whole number');
        $this->assertSame(['x'], $ignored);
        $this->assertTrue(FilterLayoutGate::empty(['order' => [], 'values' => [], 'hide' => [], 'moves' => []]));
    }

    /** Rings, necklaces and earrings, three each, and a sale collection: a category filter and a deals filter. */
    private function catalogue(): void
    {
        $this->inShop(function (): void {
            $category = fn (string $id, string $name): CatalogCategory => CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'name' => $name, 'path' => [$name], 'depth' => 0, 'hash' => $id, 'product_count' => 3]);
            $rings = $category('r', 'Rings');
            $necklaces = $category('n', 'Necklaces');
            $earrings = $category('e', 'Earrings');
            $sale = $category('s', 'SALE 50%');
            foreach ([[1, 'Gold Ring', [$rings, $sale]], [2, 'Silver Ring', [$rings, $sale]], [3, 'Pearl Ring', [$rings]], [4, 'Bar Necklace', [$necklaces, $sale]], [5, 'Leaf Necklace', [$necklaces]],
                [6, 'Heart Necklace', [$necklaces]], [7, 'Hoop Earrings', [$earrings]], [8, 'Stud Earrings', [$earrings]], [9, 'Drop Earrings', [$earrings]]] as [$id, $title, $in]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://store.test/p/$id", 'price' => '20', 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
                $product->categories()->attach(array_map(fn (CatalogCategory $c) => $c->id, $in));
            }
        });
    }

    /** @return array<string, mixed> */
    private function built(): array
    {
        return json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true);
    }
}
