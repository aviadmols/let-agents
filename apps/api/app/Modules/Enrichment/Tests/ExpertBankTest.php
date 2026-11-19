<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\BuildExpertBank;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Scanning\BankEvidence;
use App\Modules\Enrichment\Scanning\CatalogueFacts;
use App\Modules\Enrichment\Scanning\ExpertBankGate;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Scanning\LongestPhrase;
use App\Modules\Enrichment\Scanning\PatternMatch;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The expert bank (19.11): a store unlike every ready vocabulary got none, and so no filters. Here no ready vocabulary
 * is read: a model writes the bank from the store's own words, code measures it on the store's own products and takes
 * out what nothing says, a model of another family scores every item and can only take out, and code decides. A dry
 * run: a report is kept and nothing the store is read or searched with changes.
 */
final class ExpertBankTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{role: string, provider: string, model: string, schema: bool, effort: ?string, input: array<string, mixed>}> every model call, in order */
    public array $calls = [];

    /** @var list<array<string, mixed>> what the writer answers, one a round; the last one is repeated */
    public array $written = [];

    /** @var array<string, array{0: int, 1: string}|null> the checker's answer for an id; null leaves it unscored. Others get 92, keep. */
    public array $scores = [];

    /** @var list<array<string, mixed>> items the checker adds of its own */
    public array $extra = [];

    /** @var array<string, mixed>|null the checker's whole reply, when a test sets it */
    public ?array $checkerReply = null;

    /** @var int how many times the cap was asked */
    public int $capAsked = 0;

    public bool $capReached = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override(BuildExpertBank::FEATURE, true, $this->shop->id);

        $test = $this;
        $model = new class($test) implements ChatModel, StructuredChatModel
        {
            public function __construct(private readonly ExpertBankTest $test) {}

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
                $role = str_contains($system, 'You write the attribute bank') ? 'writer' : 'checker';
                $this->test->calls[] = ['role' => $role, 'provider' => $provider->value, 'model' => $model, 'schema' => $schema, 'effort' => $effort, 'input' => $input];

                if ($role === 'writer') {
                    return new ModelReply(count($this->test->written) > 1 ? array_shift($this->test->written) : $this->test->written[0], 9000, 2000);
                }
                if ($this->test->checkerReply !== null) {
                    return new ModelReply($this->test->checkerReply, 7000, 1500);
                }
                $items = [];
                foreach ((array) $input['score'] as $id) {
                    if (array_key_exists($id, $this->test->scores) && $this->test->scores[$id] === null) {
                        continue;
                    }
                    [$score, $verdict] = $this->test->scores[$id] ?? [92, 'keep'];
                    $items[] = ['id' => $id, 'score' => $score, 'verdict' => $verdict, 'reason' => 'as counted'];
                }

                return new ModelReply(['items' => [...$items, ...$this->test->extra]], 7000, 1500);
            }
        };
        $this->app->instance(ChatModel::class, $model);

        $this->app->instance(SpendGuard::class, new class($test) implements SpendGuard
        {
            public function __construct(private readonly ExpertBankTest $test) {}

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
    }

    public function test_an_english_jewellery_store_gets_a_bank_from_its_own_words_and_what_it_never_says_is_removed(): void
    {
        $this->jewellery();

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(EnrichmentExpertBank::PASSED, $stored->status);
        $this->assertSame(['ring', 'necklace', 'earrings'], array_column($stored->bank['types'], 'key'));
        $metal = $stored->bank['attributes'][0];
        $this->assertSame(['sterling_silver', 'gold_14k'], array_column($metal['values'], 'key'), 'platinum is usual in the trade and no product of this store says it');
        $this->assertSame(5, $stored->measurement['attributes']['metal']['values']['sterling_silver']['products']);
        $this->assertSame(5, $stored->measurement['attributes']['metal']['values']['gold_14k']['products']);
        $this->assertSame(['cz', 'pearl'], array_column($stored->bank['attributes'][1]['values'], 'key'));
        $this->assertSame(['cubic zirconia' => 2, 'cz' => 2], $stored->measurement['attributes']['stone']['values']['cz']['words'], 'each word counted on the products');

        $platinum = array_values(array_filter($stored->removed, fn (array $r): bool => $r['id'] === 'value:metal.platinum'));
        $this->assertSame(['round_1', 'round_2'], array_column($platinum, 'step'), 'written again in the correction, and taken out again');
        $this->assertSame([['by' => 'code', 'why' => 'no_evidence']], array_values(array_unique(array_map(fn (array $r): array => ['by' => $r['by'], 'why' => $r['why']], $platinum), SORT_REGULAR)));
        // "earrings" is no ring: a word is read as a whole word only
        $this->assertSame(4, $stored->measurement['types']['ring']['products']);
        $this->assertEquals(0.9, $stored->measurement['type_share']);
        $this->assertTrue($stored->gate['passed']);
        $this->assertSame([], $stored->gate['hard_failures']);

        // the facts carry no ready vocabulary, and the expert is told nothing but them
        $first = $this->calls[0]['input'];
        $this->assertSame(['round', 'facts'], array_keys($first));
        $this->assertSame(10, $first['facts']['products']);
        $this->assertSame('en', $first['facts']['language']);
        $this->assertSame(5, $first['facts']['words']['sterling']);
        $this->assertSame(5, $first['facts']['phrases']['sterling silver']);
        $this->assertArrayNotHasKey('templates', $first['facts']);
        $this->assertStringNotContainsString('template', (string) json_encode($first));
    }

    public function test_a_hebrew_tours_site_gets_a_bank_in_its_own_language(): void
    {
        $this->tours();
        Settings::set('enrichment.expert_bank_values_per_product', 1, $this->shop->id);

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('he', $this->calls[0]['input']['facts']['language']);
        $this->assertSame(['טיול מאורגן', 'טיול ג\'יפים', 'שייט'], array_column($stored->bank['types'], 'label'));
        $destination = $stored->bank['attributes'][0];
        $this->assertSame(['יפן', 'איטליה', 'איסלנד', 'גאורגיה'], array_column($destination['values'], 'label'), 'פרו is a destination of the trade and of no tour here');
        // "ליפן", "באיטליה", "לגאורגיה": the letters Hebrew joins before a word
        $this->assertSame([2, 2, 2, 2], array_column($stored->measurement['attributes']['destination']['values'], 'products'));
        $this->assertEquals(1.0, $stored->measurement['type_share']);
        $this->assertSame(EnrichmentExpertBank::PASSED, $stored->status);
        $this->assertStringContainsString('הבנק הניסיוני עבר', (string) $run->summary());
    }

    public function test_the_measurement_goes_back_to_the_expert_once_and_never_twice(): void
    {
        $this->jewellery();
        $first = $this->bank();
        $second = $this->bank();
        // the correction still holds something no product says
        $second['attributes'][0]['values'][] = ['key' => 'titanium', 'label' => 'Titanium', 'words' => ['titanium']];
        $this->written = [$first, $second];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(['writer', 'writer', 'checker'], array_column($this->calls, 'role'), 'one writing, one correction, one check');
        $round2 = $this->calls[1]['input'];
        $this->assertSame(2, $round2['round']);
        $this->assertSame(0.7, $round2['measured']['attributes']['stone']['share'], 'the moonstone ring still counted: one product is evidence, and too little for a filter only at the gate');
        $this->assertSame(['ring', 'necklace', 'earrings', 'bracelet'], array_column($round2['bank']['types'], 'key'));
        $this->assertContains('value:metal.platinum', array_column($round2['removed'], 'id'), 'told what code took out');
        $this->assertSame([], $round2['measured']['untyped'], 'every product has a type while the bracelet type stands');
        $this->assertArrayHasKey('handmade', $round2['measured']['untaken'], 'a frequent word no value took');

        $removed = array_column($stored->removed, null, 'id');
        $this->assertSame('round_2', $removed['value:metal.titanium']['step']);
        $this->assertSame('no_evidence', $removed['value:metal.titanium']['why']);
        $this->assertNotContains('titanium', array_column($stored->bank['attributes'][0]['values'], 'key'));
        $this->assertNotContains('value:metal.titanium', $this->calls[2]['input']['score'], 'the checker is never shown what has no evidence');
    }

    public function test_a_word_no_product_says_leaves_its_value_and_the_value_stays_on_its_other_words(): void
    {
        $this->jewellery();
        $bank = $this->bank();
        $bank['attributes'][1]['values'][0]['words'][] = 'zirconium oxide';
        $this->written = [$bank];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $cz = $stored->bank['attributes'][1]['values'][0];
        $this->assertSame(['cubic zirconia', 'cz'], $cz['words']);
        $this->assertSame(['cubic[\s\-]+zirconia', 'cz'], $cz['patterns'], 'the patterns are made by code from the words');
        $this->assertContains(['id' => 'value:stone.cz', 'kind' => 'word', 'label' => 'zirconium oxide', 'by' => 'code', 'step' => 'round_1', 'why' => 'no_evidence', 'detail' => ''], $stored->removed);
    }

    public function test_the_checker_can_only_take_out_what_it_adds_or_renames_is_ignored(): void
    {
        $this->jewellery();
        // it scores a value it made up, and gives the 14k gold a new name instead of scoring it
        $this->scores = ['value:metal.gold_14k' => null];
        $this->extra = [
            ['id' => 'value:metal.rose_gold', 'score' => 99, 'verdict' => 'keep', 'reason' => 'usual in jewellery'],
            ['id' => 'value:metal.yellow_gold', 'score' => 95, 'verdict' => 'keep', 'reason' => 'a better name for gold_14k'],
            ['id' => 'attribute:occasion', 'score' => 90, 'verdict' => 'keep', 'reason' => 'shoppers ask by occasion'],
        ];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(['attribute:occasion', 'value:metal.rose_gold', 'value:metal.yellow_gold'], $stored->review['ignored']);
        $ids = ExpertBankShape::ids($stored->bank);
        $this->assertNotContains('value:metal.rose_gold', $ids);
        $this->assertNotContains('value:metal.yellow_gold', $ids);
        $this->assertNotContains('attribute:occasion', $ids);
        $this->assertSame([], array_values(array_diff($ids, ExpertBankShape::ids(ExpertBankShape::fromReply($this->bank(), 'x')[0]))), 'nothing is in the bank that the writer did not write');
        // the value it renamed got no score of its own, and an unscored item is dropped: metal is left with one value and goes
        $removed = array_column($stored->removed, null, 'id');
        $this->assertEquals(['by' => 'code', 'why' => 'unscored'], array_intersect_key($removed['value:metal.gold_14k'], array_flip(['by', 'why'])));
        $this->assertSame('too_few_values', $removed['attribute:metal']['why']);
    }

    public function test_an_item_the_checker_leaves_unscored_is_dropped(): void
    {
        $this->jewellery();
        $this->scores = ['type:necklace' => null, 'value:stone.pearl' => null];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(['ring', 'earrings'], array_column($stored->bank['types'], 'key'));
        $this->assertEquals(['kept' => false, 'why' => 'unscored', 'score' => null], array_intersect_key($stored->review['items']['type:necklace'], array_flip(['kept', 'why', 'score'])));
        $this->assertSame(['metal'], array_column($stored->bank['attributes'], 'key'), 'stone is left with one value, and one value is no choice');
        // without the necklaces too few products have a type: the bank is held, on this store's own numbers
        $this->assertSame(EnrichmentExpertBank::HELD, $stored->status);
        $this->assertFalse($stored->gate['passed']);
    }

    public function test_a_low_score_or_a_drop_verdict_takes_the_item_out_and_a_score_at_the_threshold_stays(): void
    {
        $this->jewellery();
        $this->scores = [
            'value:stone.pearl' => [69, 'keep'],
            'type:earrings' => [95, 'drop'],
            'value:stone.cz' => [70, 'keep'],
            'value:metal.gold_14k' => [140, 'keep'],
        ];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $items = $stored->review['items'];
        $this->assertEquals(['score' => 69, 'kept' => false, 'why' => 'low_score'], array_intersect_key($items['value:stone.pearl'], array_flip(['score', 'kept', 'why'])));
        $this->assertSame('checker_drop', $items['type:earrings']['why']);
        $this->assertTrue($items['value:stone.cz']['kept'], 'at the threshold');
        $this->assertSame('unscored', $items['value:metal.gold_14k']['why'], 'a score over 100 is no score');
        $removed = array_column($stored->removed, null, 'id');
        $this->assertSame('checker', $removed['value:stone.pearl']['by']);
        $this->assertStringContainsString('69', $removed['value:stone.pearl']['detail']);
        $this->assertNotContains('earrings', array_column($stored->bank['types'], 'key'));
        // every item has its score and reason kept, whatever became of it
        $this->assertCount(4 + 2 + 2 + 3, $items, 'the four types, the two attributes and their values; platinum was gone before');
        $this->assertSame('as counted', $items['type:ring']['reason']);
    }

    public function test_a_higher_threshold_from_settings_is_the_one_that_counts(): void
    {
        $this->jewellery();
        Settings::set('enrichment.expert_bank_min_score', 95, $this->shop->id);

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        // every item was scored 92: the attributes go; the types stay, each the only one its products have (19.11)
        $this->assertSame([], $stored->bank['attributes']);
        $this->assertSame(['ring', 'necklace', 'earrings'], array_column($stored->bank['types'], 'key'));
        $this->assertSame(['no_attributes'], $stored->gate['hard_failures']);
        $this->assertSame(EnrichmentExpertBank::HELD, $stored->status);
    }

    public function test_a_value_one_product_says_is_no_filter(): void
    {
        $this->jewellery();

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $removed = array_column($stored->removed, null, 'id');
        // one ring with a moonstone, one bracelet: the checker scored both 92, and code still takes them out
        $this->assertTrue($stored->review['items']['value:stone.moonstone']['kept']);
        $this->assertEquals(['by' => 'code', 'step' => 'gate', 'why' => 'too_rare', 'detail' => '1'], array_intersect_key($removed['value:stone.moonstone'], array_flip(['by', 'step', 'why', 'detail'])));
        $this->assertSame(['round_1', 'round_2'], array_column(array_filter($stored->removed, fn (array $r): bool => $r['why'] === 'no_evidence'), 'step'), 'only platinum had no evidence');
        $this->assertSame('too_rare', $removed['type:bracelet']['why']);
        $this->assertContains(['look' => 'rare', 'ids' => ['value:stone.moonstone'], 'detail' => '1 of 10 products'], $stored->review['checklist'], 'and the checker was pointed at it');

        Settings::set('enrichment.expert_bank_min_products', 1, $this->shop->id);
        ['bank' => $lenient] = app(BuildExpertBank::class)->handle($this->shop->id);
        $this->assertContains('moonstone', array_column($lenient->bank['attributes'][1]['values'], 'key'), 'the least number of products is a setting');
    }

    public function test_an_attribute_left_with_one_value_is_dropped(): void
    {
        $this->jewellery();
        $this->scores = ['value:stone.pearl' => [20, 'drop']];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $removed = array_column($stored->removed, null, 'id');
        $this->assertEquals(['by' => 'code', 'step' => 'gate', 'why' => 'too_few_values', 'detail' => '1'], array_intersect_key($removed['attribute:stone'], array_flip(['by', 'step', 'why', 'detail'])));
        $this->assertSame(['metal'], array_column($stored->bank['attributes'], 'key'));
        $this->assertArrayNotHasKey('stone', $stored->measurement['attributes']);
    }

    public function test_an_attribute_that_reaches_too_few_of_its_products_is_dropped(): void
    {
        $this->jewellery();
        Settings::set('enrichment.expert_bank_min_attribute_share', 70, $this->shop->id);

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $removed = array_column($stored->removed, null, 'id');
        // cz and pearl are on 6 of the 10 products
        $this->assertEquals(['why' => 'too_thin', 'detail' => '0.6'], array_intersect_key($removed['attribute:stone'], array_flip(['why', 'detail'])));
        $this->assertSame(['metal'], array_column($stored->bank['attributes'], 'key'));
    }

    public function test_writer_and_checker_of_one_family_fail_before_any_call(): void
    {
        $this->jewellery();
        Settings::set('enrichment.expert_bank_checker_provider', 'anthropic');

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('enrichment::runs.expert_bank_same_family', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->capAsked);
        $this->assertNull($stored);
        $this->assertSame(0, $this->inShop(fn () => EnrichmentExpertBank::query()->count()));
    }

    public function test_by_default_an_anthropic_expert_writes_and_an_openai_model_checks_each_held_to_its_schema(): void
    {
        $this->jewellery();

        app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(['anthropic', 'anthropic', 'openai'], array_column($this->calls, 'provider'));
        $this->assertSame(['claude-opus-5-5', 'claude-opus-5-5', 'gpt-5.4-mini'], array_column($this->calls, 'model'));
        $this->assertSame([true, true, true], array_column($this->calls, 'schema'), 'structured output on every call');
        $this->assertSame(3, $this->capAsked, 'the cap is asked before each call');
    }

    public function test_a_writer_reply_that_breaks_the_schema_fails_the_run_and_is_not_mended(): void
    {
        $this->jewellery();
        $bank = $this->bank();
        unset($bank['attributes'][0]['multiple']);
        $this->written = [$bank];

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('enrichment::runs.expert_bank_schema', $run->summary_key);
        $this->assertSame(['writer'], array_column($this->calls, 'role'), 'not asked again, and the checker never asked');
        $this->assertSame(EnrichmentExpertBank::FAILED, $stored->status);
        $this->assertNull($stored->bank);
        $this->assertNotEmpty($stored->problems);
        // what the broken reply cost is still on the run and on the report
        $this->assertSame(9000, $run->input_tokens);
        $this->assertCount(1, $stored->calls);
    }

    public function test_a_checker_reply_that_breaks_the_schema_fails_the_run(): void
    {
        $this->jewellery();
        $this->checkerReply = ['items' => [['id' => 'type:ring', 'score' => 'high', 'verdict' => 'keep', 'reason' => '']]];

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(EnrichmentExpertBank::FAILED, $stored->status);
        $this->assertNull($stored->bank, 'a bank nobody checked is no bank');
        $this->assertSame(['step' => 'check_1'], $run->summary_params);

        $this->checkerReply = ['items' => [], 'add' => [['id' => 'value:metal.rose_gold']]];
        ['run' => $run] = app(BuildExpertBank::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Failed, $run->status, 'a field the schema does not have breaks it');
    }

    public function test_every_call_is_recorded_with_its_tokens_and_cost_and_each_step_says_a_hebrew_line(): void
    {
        $this->jewellery();

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(['round_1', 'round_2', 'check_1'], array_column($stored->calls, 'step'));
        $this->assertSame(['expert_bank.v3', 'expert_bank.v3', 'expert_bank_check.v1'], array_column($stored->calls, 'prompt'));
        // the writer at $4 and $20 a million, the checker at $1 and $8
        $this->assertSame([0.076, 0.076, 0.019], array_column($stored->calls, 'usd'));
        $this->assertSame(25000, $run->input_tokens);
        $this->assertSame(5500, $run->output_tokens);
        $this->assertEqualsWithDelta(0.171, (float) $run->cost_usd, 0.0001);

        $this->assertCount(6, $stored->steps);
        $this->assertSame($stored->steps, $run->output['steps']);
        $this->assertSame((string) $run->summary(), $stored->steps[5]);
        $this->assertStringContainsString('עובדות: הקוד קרא 10 מוצרים', $stored->steps[0]);
        $this->assertStringContainsString('סבב 1 (claude-opus-5-5)', $stored->steps[1]);
        $this->assertStringContainsString('סבב 2', $stored->steps[2]);
        $this->assertStringContainsString('בדיקה (gpt-5.4-mini)', $stored->steps[3]);
        $this->assertStringContainsString('שער הקוד', $stored->steps[4]);
        $this->assertSame($stored->id, $run->output['expert_bank']);
    }

    public function test_the_cap_is_asked_first_and_a_cap_reached_asks_no_model(): void
    {
        $this->jewellery();
        $this->capReached = true;

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('enrichment::runs.expert_bank_spend_cap', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(EnrichmentExpertBank::FAILED, $stored->status);
    }

    public function test_the_flag_is_off_by_default_and_nothing_runs_without_it(): void
    {
        $this->jewellery();
        Features::override(BuildExpertBank::FEATURE, false, $this->shop->id);
        $this->assertFalse(Features::enabled(BuildExpertBank::FEATURE), 'off unless turned on');

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($stored);
        $this->assertSame([], $this->calls);

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug])
            ->expectsOutput('בנק המאפיינים הניסיוני כבוי לחנות הזאת.')->assertSuccessful();
        $this->assertSame([], $this->calls);
    }

    public function test_the_same_facts_are_not_asked_twice_unless_forced(): void
    {
        $this->jewellery();
        $first = app(BuildExpertBank::class)->handle($this->shop->id)['bank'];
        $this->assertCount(3, $this->calls);

        ['run' => $run, 'bank' => $again] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertCount(3, $this->calls, 'nothing asked');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('enrichment::runs.expert_bank_unchanged', $run->summary_key);

        // one more product: other facts, another fingerprint
        $this->product('11', 'Thea Sterling Silver Necklace with Pearl', '', []);
        $changed = app(BuildExpertBank::class)->handle($this->shop->id)['bank'];
        $this->assertNotSame($first->fingerprint, $changed->fingerprint);
        $this->assertCount(6, $this->calls);

        $forced = app(BuildExpertBank::class)->handle($this->shop->id, true)['bank'];
        $this->assertNotSame($changed->id, $forced->id);
        $this->assertSame($changed->fingerprint, $forced->fingerprint);
        $this->assertCount(9, $this->calls);
    }

    public function test_a_dry_run_changes_nothing_the_store_is_read_or_searched_with(): void
    {
        $this->jewellery();
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        app(ImportVocabulary::class)->handle($this->shop->id, [
            'schema_version' => 1, 'key' => 'jewelry', 'name' => $label('תכשיטים', 'Jewelry'), 'root_category_external_id' => '*',
            'product_types' => [['key' => 'ring', 'label' => $label('טבעת', 'Ring'), 'patterns' => ['\bring\b']]],
            'attributes' => [],
        ]);
        Queue::fake();
        $before = $this->snapshot();

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertSame(EnrichmentExpertBank::PASSED, $stored->status, 'even a bank that passes');
        $changed = array_keys(array_diff_assoc($this->snapshot(), $before));
        sort($changed);
        // the report, the run, and the run's own account of its calls: nothing else
        $this->assertSame([], array_values(array_filter($changed, fn (string $table): bool => $table !== 'enrichment_expert_banks' && ! str_starts_with($table, 'run') && ! str_starts_with($table, 'ai_'))), implode(', ', $changed));
        $this->assertContains('enrichment_expert_banks', $changed);
        foreach (['enrichment_vocabularies', 'enrichment_facts', 'enrichment_code_readings', 'enrichment_schema_proposals', 'catalog_products'] as $table) {
            $this->assertArrayHasKey($table, $before);
            $this->assertNotContains($table, $changed);
        }
        Queue::assertNothingPushed();
    }

    public function test_the_command_runs_one_named_shop_and_shows_the_report_as_filters(): void
    {
        $this->jewellery();

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug])
            ->expectsOutputToContain('עובדות: הקוד קרא 10 מוצרים')
            ->expectsOutputToContain('הבנק הניסיוני עבר')
            ->expectsOutputToContain('הדוח נשמר: enrichment_expert_banks')
            ->expectsOutput('Metal (10 מוצרים)')
            ->expectsOutput('  Sterling silver: 5')
            ->assertSuccessful();
        $this->assertCount(3, $this->calls);

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, '--show' => true])
            ->expectsOutput('סוג מוצר (9 מוצרים)')
            ->expectsOutput('  Ring: 4')
            ->expectsOutput('  Necklace: 3')
            ->expectsOutput('Stone (6 מוצרים)')
            ->expectsOutput('  Cubic zirconia: 4')
            ->expectsOutput('  too_rare: 2')
            ->assertSuccessful();
        $this->assertCount(3, $this->calls, 'showing asks no model');

        $this->artisan('enrichment', ['step' => 'expert-bank', '--scheduled' => true])->assertFailed();
        $this->artisan('enrichment', ['step' => 'expert-bank'])->assertFailed();
        $this->assertCount(3, $this->calls, 'never for every shop');
    }

    public function test_a_demo_scan_makes_a_bank_that_passed_the_vocabulary_at_once(): void
    {
        // FirstScan 'bank' (19.11): a demo store's scan builds the new filters with no person between
        $this->jewellery();

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, '--activate-passed' => true])->assertSuccessful();

        $this->assertSame([EnrichmentExpertBank::ACTIVATED], $this->inShop(fn () => EnrichmentExpertBank::query()->pluck('status')->all()));
    }

    public function test_a_hebrew_final_letter_is_read_with_an_ending_too_and_a_word_inside_a_word_is_not(): void
    {
        $this->assertSame('אדו[םמ]', ExpertBankShape::pattern('אדום'));
        $this->assertTrue(PatternMatch::any('שמלות אדומות לערב', [ExpertBankShape::pattern('אדום')]));
        $this->assertTrue(PatternMatch::any('שמלה באדום', [ExpertBankShape::pattern('אדום')]));
        $this->assertSame('זהב[\s\-]+לב[ןנ]', ExpertBankShape::pattern('זהב לבן'));
        $this->assertTrue(PatternMatch::any('טבעת זהב-לבן', [ExpertBankShape::pattern('זהב לבן')]));
        $this->assertFalse(PatternMatch::any('silver earrings', [ExpertBankShape::pattern('ring')]));
        $this->assertTrue(PatternMatch::any('two rings', [ExpertBankShape::pattern('ring')]));
        // words are cleaned as the products' text is; one letter, no letter, or a field separator is no word
        $this->assertSame(['white gold', 'מ"מ זהב'], ExpertBankShape::words(['  White   Gold ', 'S', '14', 'a;b', 'white gold', 'מ״מ זהב']));
    }

    public function test_what_does_not_hold_its_form_is_left_out_and_never_rewritten(): void
    {
        [$bank, $removed] = ExpertBankShape::fromReply([
            'types' => [
                ['key' => 'ring', 'label' => 'Ring', 'words' => ['ring']],
                ['key' => 'Ring Two', 'label' => 'Ring', 'words' => ['ring']],
                ['key' => 'ring', 'label' => 'Ring again', 'words' => ['rings']],
                ['key' => 'chain', 'label' => ' ', 'words' => ['chain']],
                ['key' => 'pin', 'label' => 'Pin', 'words' => ['x']],
            ],
            'attributes' => [
                ['key' => 'metal', 'label' => 'Metal', 'multiple' => false, 'types' => ['ring', 'tiara'], 'values' => [['key' => 'gold', 'label' => 'Gold', 'words' => ['14k gold']]]],
                ['key' => 'clasp', 'label' => 'Clasp', 'multiple' => false, 'types' => ['tiara'], 'values' => [['key' => 'lobster', 'label' => 'Lobster', 'words' => ['lobster']]]],
                ['key' => 'empty', 'label' => 'Empty', 'multiple' => true, 'types' => [], 'values' => []],
            ],
            'notes' => [],
        ], 'round_1');

        $this->assertSame(['type:ring'], array_column($bank['types'], 'id'));
        $this->assertSame(['attribute:metal'], array_column($bank['attributes'], 'id'));
        $this->assertSame(['ring'], $bank['attributes'][0]['types'], 'a type the bank does not have is no type of an attribute');
        $this->assertSame(['bad_key', 'duplicate', 'no_label', 'no_words', 'no_type', 'no_values'], array_column($removed, 'why'));
    }

    public function test_the_checklist_points_at_what_the_numbers_show(): void
    {
        $this->jewellery();
        $this->product('11', 'Ruby Rose Gold Ring', '', [], ['payload' => ['title' => 'Ruby Rose Gold Ring', 'description' => 'A rose shaped ring in 14k gold with sterling silver band.', 'tags' => [], 'attributes' => [], 'meta' => []]]);
        $bank = $this->bank();
        // "gold" alone is no second reading of "14k gold" (the longer phrase takes it), and both metals are written on the new ring
        $bank['attributes'][0]['values'][] = ['key' => 'gold', 'label' => 'Gold', 'words' => ['gold']];
        // a word every description says reads the same products as each metal
        $bank['attributes'][0]['values'][] = ['key' => 'studio_made', 'label' => 'Studio', 'words' => ['studio']];
        $bank['attributes'][] = ['key' => 'clasp', 'label' => 'Clasp', 'multiple' => false, 'types' => ['necklace'], 'values' => [['key' => 'silver_clasp', 'label' => 'Silver', 'words' => ['sterling silver']], ['key' => 'cz', 'label' => 'CZ', 'words' => ['cz']]]];

        ['facts' => $facts, 'texts' => $texts] = $this->inShop(fn () => CatalogueFacts::of(CatalogProduct::query()->orderBy('external_id')->get(), 20, 'en'));
        [$shaped] = ExpertBankShape::fromReply($bank, 'round_1');
        $measured = (new BankEvidence($texts))->measure($shaped, $facts['words'] + $facts['phrases'], 2);
        $list = ExpertBankGate::checklist($shaped, $measured, 2);
        $looks = fn (string $look): array => array_values(array_filter($list, fn (array $line): bool => $line['look'] === $look));

        $overlaps = array_map(fn (array $line): string => implode('+', $line['ids']), $looks('overlap'));
        $this->assertContains('value:metal.gold_14k+value:metal.gold', $overlaps);
        $this->assertContains('value:metal.sterling_silver+value:metal.gold_14k', $overlaps, 'two metals read on one product');
        $this->assertContains(['value:stone.cz', 'value:clasp.cz'], array_column($looks('two_meanings'), 'ids'), 'one word, two items');
        $this->assertContains(['value:clasp.silver_clasp'], array_column($looks('two_meanings'), 'ids'), 'said mostly by products of other types');
        $this->assertContains(['value:stone.moonstone'], array_column($looks('rare'), 'ids'));
        $this->assertSame(1, $measured['attributes']['metal']['values']['gold']['products'], 'only the rose gold ring: inside "14k gold" the word is the longer phrase\'s');
        $this->assertSame(11, $measured['attributes']['metal']['overlapping'], 'every metal of the ten is read beside the studio, and the new ring has three');

        // a one-value attribute whose values read the same products fails the bank whatever its shares
        $gate = ExpertBankGate::bank($shaped, $measured, 0.1, 0.1);
        $this->assertContains('overlap:metal', $gate['hard_failures']);
        $this->assertFalse($gate['passed']);
    }

    public function test_a_value_nearly_every_product_of_its_attribute_holds_separates_nothing_and_code_takes_it_out_though_the_checker_kept_it(): void
    {
        $this->jewellery();
        $bank = $this->bank();
        // every product says "handmade"; two say "pearl"
        $bank['attributes'][] = ['key' => 'craft', 'label' => 'Craft', 'multiple' => true, 'types' => [], 'values' => [
            ['key' => 'handmade', 'label' => 'Handmade', 'words' => ['handmade']], ['key' => 'pearl_set', 'label' => 'Pearl set', 'words' => ['pearl']],
        ]];
        $this->written = [$bank];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $this->assertTrue($stored->review['items']['value:craft.handmade']['kept'], 'the checker kept it');
        $this->assertContains(['look' => 'nearly_universal', 'ids' => ['attribute:craft', 'value:craft.handmade'], 'detail' => '10 of the 10 products with a value have this one'], $stored->review['checklist']);
        $removed = array_column($stored->removed, null, 'id');
        $this->assertSame(['by' => 'code', 'step' => 'gate', 'why' => 'separates_nothing', 'detail' => '10 of 10'], array_intersect_key($removed['value:craft.handmade'], array_flip(['by', 'step', 'why', 'detail'])));
        $this->assertSame('too_few_values', $removed['attribute:craft']['why'], 'left with one value, the attribute is no filter');
        $this->assertNotContains('craft', array_column($stored->bank['attributes'], 'key'));
        $this->assertSame(['metal', 'stone'], array_column($stored->bank['attributes'], 'key'), 'values of ordinary shares stay');
    }

    public function test_the_share_a_value_may_hold_is_a_bounded_setting(): void
    {
        $this->jewellery();
        $bank = $this->bank();
        $bank['attributes'][] = ['key' => 'craft', 'label' => 'Craft', 'multiple' => true, 'types' => [], 'values' => [
            ['key' => 'handmade', 'label' => 'Handmade', 'words' => ['handmade']], ['key' => 'pearl_set', 'label' => 'Pearl set', 'words' => ['pearl']],
        ]];
        $this->written = [$bank];
        // 100%: only a value every product of its attribute holds; 10 of 10 still goes
        Settings::set('enrichment.expert_bank_max_value_share', 100, $this->shop->id);
        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);
        $this->assertContains('value:craft.handmade', array_column(array_filter($stored->removed, fn (array $r): bool => $r['why'] === 'separates_nothing'), 'id'));
        $this->assertEquals(1, $stored->gate['limits']['max_value_share']);

        $this->expectException(InvalidSettingValue::class);
        Settings::set('enrichment.expert_bank_max_value_share', 30, $this->shop->id);
    }

    public function test_a_value_that_says_it_is_not_known_is_no_choice(): void
    {
        $this->jewellery();
        $bank = $this->bank();
        $bank['attributes'][1]['values'][] = ['key' => 'unconfirmed', 'label' => 'Stone not confirmed', 'words' => ['moonstone']];
        $bank['attributes'][1]['values'][] = ['key' => 'na', 'label' => 'N/A', 'words' => ['pearl']];
        $bank['attributes'][1]['values'][] = ['key' => 'he_unknown', 'label' => 'אבן לא ידועה', 'words' => ['cz']];
        $this->written = [$bank];

        ['bank' => $stored] = app(BuildExpertBank::class)->handle($this->shop->id);

        $removed = array_values(array_filter($stored->removed, fn (array $r): bool => $r['why'] === 'not_a_choice'));
        $this->assertSame(['value:stone.unconfirmed', 'value:stone.na', 'value:stone.he_unknown'], array_values(array_unique(array_column($removed, 'id'))));
        $this->assertSame(['round_1', 'round_2'], array_values(array_unique(array_column($removed, 'step'))), 'taken out in each round, before any checker');
        $this->assertSame(['cz', 'pearl'], array_column($stored->bank['attributes'][1]['values'], 'key'));
        $this->assertNotContains('value:stone.unconfirmed', $this->calls[2]['input']['score']);
        // the list is of uncertainty only: a value that merely sounds negative is not taken
        $this->assertSame([], ExpertBankGate::notChoices(['types' => [], 'attributes' => [['id' => 'attribute:a', 'key' => 'a', 'label' => 'A', 'multiple' => false, 'types' => [], 'values' => [
            ['id' => 'value:a.known', 'key' => 'known', 'label' => 'Well known', 'words' => ['known']], ['id' => 'value:a.no', 'key' => 'no', 'label' => 'No stone', 'words' => ['no stone']],
        ]]]], 'round_1')[1]);
    }

    public function test_within_one_attribute_the_longest_phrase_wins_and_another_attribute_reads_its_own_words(): void
    {
        $texts = [];
        foreach (['Aurora Sterling Silver Ring', '14K Gold Over Sterling Silver Ring', 'Sterling silver chain with a 14K gold over sterling silver charm', 'Sterling Silvery Ring', 'Silver Grey Ring'] as $i => $title) {
            $texts[] = ['id' => (string) $i, 'title' => $title, 'head' => mb_strtolower($title), 'full' => mb_strtolower($title)];
        }
        $item = fn (string $attribute, string $key, array $words): array => ['id' => "value:{$attribute}.{$key}", 'key' => $key, 'label' => $key, 'words' => $words];
        $bank = ['types' => [['id' => 'type:ring', 'key' => 'ring', 'label' => 'Ring', 'words' => ['ring']]], 'attributes' => [
            ['id' => 'attribute:metal', 'key' => 'metal', 'label' => 'Metal', 'multiple' => false, 'types' => [], 'values' => [
                $item('metal', 'sterling_silver', ['sterling silver']), $item('metal', 'gold_over_silver', ['14k gold over sterling silver', 'gold over sterling silver']),
            ]],
            ['id' => 'attribute:colour', 'key' => 'colour', 'label' => 'Colour', 'multiple' => true, 'types' => [], 'values' => [
                $item('colour', 'silver', ['silver']), $item('colour', 'grey', ['grey']),
            ]],
        ]];

        $measured = (new BankEvidence($texts))->measure($bank, [], 1);
        $metal = $measured['attributes']['metal'];

        $this->assertSame(2, $metal['values']['sterling_silver']['products'], 'the plain one and the chain: inside the longer phrase it is not read again');
        $this->assertSame(2, $metal['values']['gold_over_silver']['products']);
        $this->assertSame(['14k gold over sterling silver' => 2, 'gold over sterling silver' => 2], $metal['values']['gold_over_silver']['words'], 'a shorter word of the same value inside its own longer phrase is no conflict');
        $this->assertSame(1, $metal['overlapping'], 'the chain says both, each in its own words');
        $this->assertSame(4, $measured['attributes']['colour']['values']['silver']['products'], 'another attribute reads its own words: "silver" is no metal\'s there');
        $this->assertSame(3, $metal['products'], '"silvery" is no whole word of it');

        // the same rule, alone: equal lengths both stand; only a strictly longer phrase of another value takes a span
        $this->assertSame(['a' => [0, 3], 'b' => [0, 3]], LongestPhrase::claims('abc def', ['a' => ['abc'], 'b' => ['abc']]));
        $this->assertSame(['long' => [0, 7]], LongestPhrase::claims('abc def', ['short' => ['def'], 'long' => ['abc def']]));
        $this->assertSame(['short' => [8, 3], 'long' => [0, 7]], LongestPhrase::claims('abc def def', ['short' => ['def'], 'long' => ['abc def']]), 'a word said again outside the phrase stands');

        // and the expert is told so, in a new version of its prompt; the released one is not edited
        $this->assertSame(3, BuildExpertBank::PROMPTS['expert_bank'], 'v3 since 19.11: who it is for');
        $prompts = dirname(__DIR__).'/Prompts/';
        $this->assertStringContainsString('code reads the longest phrase first', (string) file_get_contents($prompts.'expert_bank.v2.md'));
        $this->assertStringNotContainsString('longest phrase', (string) file_get_contents($prompts.'expert_bank.v1.md'));
        $this->assertSame(file_get_contents($prompts.'expert_bank.v1.schema.json'), file_get_contents($prompts.'expert_bank.v2.schema.json'));
    }

    /** Ten products of a jewellery store that writes in English: rings, necklaces, earrings, one bracelet. */
    private function jewellery(): void
    {
        foreach ([
            '01' => ['Aurora Sterling Silver Ring with Cubic Zirconia', 'Rings'],
            '02' => ['Luna Sterling Silver Ring', 'Rings'],
            '03' => ['Stella 14k Gold Ring with CZ', 'Rings'],
            '04' => ['Nova 14k Gold Necklace', 'Necklaces'],
            '05' => ['Iris Sterling Silver Necklace with Pearl', 'Necklaces'],
            '06' => ['Vega 14k Gold Earrings with Pearl', 'Earrings'],
            '07' => ['Lyra Sterling Silver Earrings with CZ', 'Earrings'],
            '08' => ['Orion 14k Gold Necklace with Cubic Zirconia', 'Necklaces'],
            '09' => ['Maia Sterling Silver Bracelet', 'Bracelets'],
            '10' => ['Cleo 14k Gold Ring with Moonstone', 'Rings'],
        ] as $id => [$title, $type]) {
            $this->product($id, $title, '', [], ['payload' => ['title' => $title, 'description' => 'Handmade in our studio. Free gift box.', 'product_type' => $type, 'tags' => ['handmade'], 'attributes' => [], 'meta' => []]]);
        }
        $this->shop->update(['content_locale' => 'en']);
        $this->written = [$this->bank()];
    }

    /** @return array<string, mixed> what an expert might write for it: right for the most part, with a metal no product says */
    private function bank(): array
    {
        $item = fn (string $key, string $label, array $words): array => ['key' => $key, 'label' => $label, 'words' => $words];

        return [
            'types' => [$item('ring', 'Ring', ['ring']), $item('necklace', 'Necklace', ['necklace']), $item('earrings', 'Earrings', ['earrings']), $item('bracelet', 'Bracelet', ['bracelet'])],
            'attributes' => [
                ['key' => 'metal', 'label' => 'Metal', 'multiple' => false, 'types' => [], 'values' => [
                    $item('sterling_silver', 'Sterling silver', ['sterling silver']), $item('gold_14k', '14k gold', ['14k gold']), $item('platinum', 'Platinum', ['platinum']),
                ]],
                ['key' => 'stone', 'label' => 'Stone', 'multiple' => true, 'types' => [], 'values' => [
                    $item('cz', 'Cubic zirconia', ['cubic zirconia', 'CZ']), $item('pearl', 'Pearl', ['pearl']), $item('moonstone', 'Moonstone', ['moonstone']),
                ]],
            ],
            'notes' => ['No platinum seen; kept as a guess.'],
        ];
    }

    /** Eight tours of a site that writes in Hebrew. */
    private function tours(): void
    {
        foreach ([
            '1' => 'טיול מאורגן ליפן 12 ימים',
            '2' => 'טיול מאורגן לאיטליה 8 ימים',
            '3' => 'טיול מאורגן ליפן בפריחת הדובדבן',
            '4' => 'טיול ג\'יפים לאיסלנד',
            '5' => 'טיול ג\'יפים לגאורגיה',
            '6' => 'שייט נהרות באיטליה',
            '7' => 'שייט לאיסלנד ולנורבגיה',
            '8' => 'טיול מאורגן לגאורגיה ולארמניה',
        ] as $id => $title) {
            $this->product($id, $title, 'כולל טיסות, מלונות ומדריך ישראלי.', []);
        }
        $item = fn (string $key, string $label, array $words): array => ['key' => $key, 'label' => $label, 'words' => $words];
        $this->written = [[
            'types' => [$item('organised_tour', 'טיול מאורגן', ['טיול מאורגן']), $item('jeep_tour', 'טיול ג\'יפים', ['טיול ג׳יפים']), $item('cruise', 'שייט', ['שייט'])],
            'attributes' => [
                ['key' => 'destination', 'label' => 'יעד', 'multiple' => true, 'types' => [], 'values' => [
                    $item('japan', 'יפן', ['יפן']), $item('italy', 'איטליה', ['איטליה']), $item('iceland', 'איסלנד', ['איסלנד']), $item('georgia', 'גאורגיה', ['גאורגיה']), $item('peru', 'פרו', ['פרו']),
                ]],
            ],
            'notes' => [],
        ]];
    }

    /** @return array<string, string> every table's rows, hashed */
    private function snapshot(): array
    {
        $out = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
            $out[$table->name] = md5((string) json_encode(DB::table($table->name)->get()));
        }

        return $out;
    }
}
