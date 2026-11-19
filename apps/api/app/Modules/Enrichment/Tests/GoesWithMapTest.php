<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\BuildGoesWithMap;
use App\Modules\Enrichment\Contracts\GoesWithPartners;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentGoesWithMap;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store's goes-with map (BuildGoesWithMap): which of its own kinds a shopper needs together, written by one family from
 * the list of kinds, scored by the other, kept by code where both kinds hold products; never asked twice for the same
 * kinds; read by GoesWithPartners beside the store's own complement relations.
 */
final class GoesWithMapTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{role: string, provider: string, model: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> the writer's reply */
    public array $written = ['pairs' => [], 'notes' => []];

    /** @var array<string, array{0: int, 1: string}|null> a pair id's score and verdict; null: left unscored */
    public array $scores = [];

    public int $capAsked = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override(BuildGoesWithMap::FEATURE, true, $this->shop->id);

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel, StructuredChatModel
        {
            public function __construct(private readonly GoesWithMapTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user);
            }

            public function structured(AiProviderName $provider, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user);
            }

            private function answer(AiProviderName $provider, string $model, string $system, string $user): ModelReply
            {
                $input = (array) json_decode($user, true);
                $role = str_contains($system, 'You know how the products of a trade are used together') ? 'writer' : 'checker';
                $this->test->calls[] = ['role' => $role, 'provider' => $provider->value, 'model' => $model, 'input' => $input];
                if ($role === 'writer') {
                    return new ModelReply($this->test->written, 2500, 900);
                }
                $items = [];
                foreach ((array) $input['score'] as $id) {
                    if (array_key_exists($id, $this->test->scores) && $this->test->scores[$id] === null) {
                        continue;
                    }
                    [$score, $verdict] = $this->test->scores[$id] ?? [92, 'keep'];
                    $items[] = ['id' => $id, 'score' => $score, 'verdict' => $verdict, 'reason' => 'as the trade has it'];
                }
                // and a pair the checker invents, which code ignores
                $items[] = ['id' => 'drill>socks', 'score' => 99, 'verdict' => 'keep', 'reason' => 'invented'];

                return new ModelReply(['items' => $items], 3000, 700);
            }
        });
        $this->app->instance(SpendGuard::class, new class($test) implements SpendGuard
        {
            public function __construct(private readonly GoesWithMapTest $test) {}

            public function assertCanSpend(float $estimatedUsd, bool $forShopper = false): void
            {
                $this->test->capAsked++;
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

    public function test_a_hardware_store_gets_a_map_of_its_own_kinds_and_code_keeps_only_what_both_models_and_the_kinds_allow(): void
    {
        $this->hardware();
        $this->written = ['pairs' => [
            ['type' => 'drill', 'partner_type' => 'drill_bit', 'why' => 'המקדחים שעובדים עם המקדחה'],
            ['type' => 'drill', 'partner_type' => 'screw', 'why' => 'הברגים שמבריגים איתה'],
            ['type' => 'drill', 'partner_type' => 'drill_bit', 'why' => 'again'],
            ['type' => 'drill', 'partner_type' => 'drill', 'why' => 'itself'],
            ['type' => 'drill', 'partner_type' => 'ladder', 'why' => 'no such kind here'],
            ['type' => 'glue', 'partner_type' => 'primer', 'why' => 'פריימר לפני הדבק'],
            ['type' => 'glue', 'partner_type' => 'screw', 'why' => 'speculative'],
        ], 'notes' => []];
        $this->scores = ['glue>screw' => [35, 'drop']];

        $run = app(BuildGoesWithMap::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['writer', 'checker'], array_column($this->calls, 'role'));
        $this->assertSame(['anthropic', 'openai'], array_column($this->calls, 'provider'), 'one family writes, the other checks');
        $this->assertSame(2, $this->capAsked, 'every call asked the spend guard first');
        $this->assertSame(['drill', 'drill_bit', 'screw', 'glue', 'primer'], array_column($this->calls[0]['input']['types'], 'key'), 'the kinds with products, the most products first; "ladder" holds none and is not given');
        $this->assertSame(['key' => 'drill', 'label' => 'מקדחה', 'products' => 3, 'examples' => ['מקדחה רוטטת Makita 18V', 'מקדחה Bosch', 'מברגה Makita']], $this->calls[0]['input']['types'][0]);
        $this->assertSame(['drill>drill_bit', 'drill>screw', 'glue>primer', 'glue>screw'], $this->calls[1]['input']['score'], 'the checker scores what code kept of the shape');

        $stored = $this->inShop(fn () => EnrichmentGoesWithMap::query()->sole());
        $this->assertSame(EnrichmentGoesWithMap::PASSED, $stored->status);
        $this->assertSame([['drill', 'drill_bit', 92], ['drill', 'screw', 92], ['glue', 'primer', 92]], array_map(fn (array $p): array => [$p['type'], $p['partner_type'], $p['score']], $stored->pairs));
        $removed = array_map(fn (array $r): string => $r['type'].'>'.$r['partner_type'].':'.$r['why_removed'], $stored->removed);
        $this->assertSame(['drill>drill_bit:repeated', 'drill>drill:same_type', 'drill>ladder:unknown_type', 'glue>screw:dropped'], $removed);
        $this->assertSame(['drill>socks'], $stored->review['ignored'], 'what the checker added is ignored');
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'every call is accounted');
        $this->assertStringContainsString('מפת המוצרים המשלימים נשמרה: 3 זוגות', (string) $run->summary());

        // read where the assistant reads it
        $partners = $this->inShop(fn () => app(GoesWithPartners::class)->partnersOf($this->shop->id, 'drill'));
        $this->assertSame(['drill_bit', 'screw'], array_column($partners, 'type'));
        $this->assertSame(['מקדח', 'בורג'], array_column($partners, 'label'));
        $this->assertSame([], $this->inShop(fn () => app(GoesWithPartners::class)->partnersOf($this->shop->id, 'screw')), 'the direction holds: a screw needs no drill');

        // the same kinds are never asked twice; a kind added asks again
        $this->calls = [];
        $again = app(BuildGoesWithMap::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('לא נשאל שוב', (string) $again->summary());
        $this->assertSame(1, $this->inShop(fn () => EnrichmentGoesWithMap::query()->count()));
    }

    public function test_the_two_families_the_same_fails_before_any_call_and_off_it_does_nothing(): void
    {
        $this->hardware();
        Settings::set('enrichment.goes_with_checker_provider', 'anthropic');

        $run = app(BuildGoesWithMap::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->capAsked);

        Settings::set('enrichment.goes_with_checker_provider', 'openai');
        Features::override(BuildGoesWithMap::FEATURE, false, $this->shop->id);
        $run = app(BuildGoesWithMap::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('כבויה', (string) $run->summary());
        $this->assertSame([], $this->calls);
    }

    public function test_the_stores_own_complement_relations_are_read_only_where_the_shop_keeps_them(): void
    {
        $this->hardware();
        $drill = $this->inShop(fn () => CatalogProduct::query()->where('external_id', 'd1')->sole());
        $glue = $this->inShop(fn () => CatalogProduct::query()->where('external_id', 'g1')->sole());
        $this->inShop(fn () => EnrichmentProductRelation::query()->create(['shop_id' => $this->shop->id, 'product_id' => $drill->id, 'related_product_id' => $glue->id,
            'kind' => RelationKind::Complement, 'source' => 'merchant', 'score' => 150, 'reasons' => ['merchant' => 'cross_sell'], 'computed_at' => now()]));

        Features::override('enrichment.relations', true, $this->shop->id);
        $this->assertSame([['external_id' => 'g1', 'score' => 150, 'source' => 'merchant']], $this->inShop(fn () => app(GoesWithPartners::class)->complementsOf($this->shop->id, 'd1')));
        Features::override('enrichment.relations', false, $this->shop->id);
        $this->assertSame([], $this->inShop(fn () => app(GoesWithPartners::class)->complementsOf($this->shop->id, 'd1')));
        $this->assertFalse($this->inShop(fn () => app(GoesWithPartners::class)->hasMap($this->shop->id)));
    }

    /** A hardware store: drills, bits, screws, glue and primer with their kinds approved, and a kind no product holds. */
    private function hardware(): void
    {
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'hardware', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
            'definition' => ['schema_version' => 1, 'key' => 'hardware', 'name' => $label('כלי עבודה', 'Hardware'), 'root_category_external_id' => '*',
                'product_types' => [
                    ['key' => 'drill', 'label' => $label('מקדחה', 'Drill')], ['key' => 'drill_bit', 'label' => $label('מקדח', 'Drill bit')], ['key' => 'screw', 'label' => $label('בורג', 'Screw')],
                    ['key' => 'glue', 'label' => $label('דבק', 'Glue')], ['key' => 'primer', 'label' => $label('פריימר', 'Primer')], ['key' => 'ladder', 'label' => $label('סולם', 'Ladder')],
                ],
                'attributes' => []]]));
        $products = [
            ['d1', 'מקדחה רוטטת Makita 18V', 'drill'], ['d2', 'מקדחה Bosch', 'drill'], ['d3', 'מברגה Makita', 'drill'],
            ['b1', 'סט מקדחים Makita', 'drill_bit'], ['b2', 'מקדח לבטון Bosch', 'drill_bit'],
            ['s1', 'ברגים לדק', 'screw'], ['s2', 'ברגים לגבס', 'screw'],
            ['g1', 'דבק עץ', 'glue'], ['p1', 'פריימר לדבק', 'primer'],
        ];
        foreach ($products as [$id, $title, $type]) {
            $product = $this->product($id, $title, $title, []);
            $this->inShop(fn () => EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
                'value_text' => $type, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 't'.$id]));
        }
    }
}
