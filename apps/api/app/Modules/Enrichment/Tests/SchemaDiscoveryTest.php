<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\DecideSchemaProposal;
use App\Modules\Enrichment\Actions\DiscoverSchema;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's own vocabulary: code counts a sample of its products, a model writes a vocabulary
 * from the counts, code checks and measures it, a model of the other family reviews it, and the
 * operator approves it into the shop's vocabulary.
 */
final class SchemaDiscoveryTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, system: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> replies, in order */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();

        $rings = [
            ['טבעת וינטאג משובצת יהלום - מק"ט 60521, זהב 14K', 'טבעת זהב צהוב 14 קארט, משקל התכשיט 1.1 גרם, בשיבוץ 1 יהלומים, ששוקלים בסך הכל 0.2 קארט', ['זהב צהוב']],
            ['טבעת סוליטר משובצת יהלום, זהב 18K', 'טבעת זהב לבן 18 קארט, משקל התכשיט 3.2 גרם, בשיבוץ 1 יהלומים, ששוקלים בסך הכל 0.5 קארט', ['זהב לבן']],
            ['טבעת וינטג\' משובצת אמטיסט, זהב 14K', 'טבעת זהב צהוב 14 קארט, משקל התכשיט 5.95 גרם', ['זהב צהוב']],
        ];
        $pendants = [
            ['תליון וינטאג עיגולים משובצים יהלומים, זהב 14K', 'תליון זהב לבן 14 קארט, משקל התכשיט 3.2 גרם, בשיבוץ 24 יהלומים', ['זהב לבן']],
            ['תליון סוליטר משובץ ציטרין, זהב 14K', 'תליון זהב צהוב 14 קארט, משקל התכשיט 2.1 גרם', ['זהב צהוב']],
        ];
        $id = 0;
        foreach (['טבעות' => $rings, 'תליונים' => $pendants] as $type => $products) {
            foreach ($products as [$title, $description, $tags]) {
                $id++;
                $this->product((string) $id, $title, $description, [], ['payload' => [
                    'title' => $title, 'description' => $description, 'product_type' => $type, 'tags' => $tags, 'categories' => [], 'attributes' => [], 'meta' => [],
                ]]);
            }
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly SchemaDiscoveryTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = compact('provider', 'model', 'system', 'user');

                return new ModelReply(array_shift($this->test->replies) ?? [], 4000, 1500);
            }
        });
    }

    public function test_code_counts_the_sample_across_the_kinds_the_shop_sells(): void
    {
        ['run' => $run, 'proposal' => $proposal] = app(DiscoverSchema::class)->handle($this->shop->id, 4);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame([], $this->calls, 'no model is called to count');
        $stats = $proposal->stats;
        $this->assertSame(4, $stats['sampled']);
        $this->assertSame(['טבעות' => 2, 'תליונים' => 2], $stats['fields']['product_type']['values'], 'two of each kind, in turn');
        $this->assertArrayHasKey('mass', $stats['measurements']);
        $this->assertArrayHasKey('משקל התכשיט', $stats['measurements']['mass']['before'], 'the words before the weight, for its name');
        $this->assertSame('jewelry', array_key_first($stats['templates']));
        $this->assertSame('jewelry', $proposal->template);
        $this->assertSame(EnrichmentSchemaProposal::DISCOVERED, $proposal->status);

        // The same products give the same counts: nothing new is kept.
        $again = app(DiscoverSchema::class)->handle($this->shop->id, 4);
        $this->assertNull($again['proposal']);
        $this->assertSame(1, $this->inShop(fn () => EnrichmentSchemaProposal::query()->count()));
    }

    public function test_the_proposal_is_written_checked_measured_and_reviewed_by_the_other_family(): void
    {
        $proposal = app(DiscoverSchema::class)->handle($this->shop->id)['proposal'];
        $this->replies = [
            ['vocabulary' => $this->vocabulary(), 'sources' => ['metal' => 'tags'], 'notes' => ['Started from jewelry.']],
            ['verdict' => 'good', 'drop' => [['kind' => 'value', 'key' => 'metal.rose_gold', 'why' => 'no product says it']], 'missing' => [['key' => 'stone_count', 'why' => 'בשיבוץ N יהלומים']], 'notes' => []],
        ];

        $run = app(ProposeSchema::class)->handle($proposal);
        $proposal->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'), 'written by one family, reviewed by the other');
        $this->assertStringNotContainsString('"sample"', $this->calls[0]['user'], 'the sample\'s ids are not sent');
        $this->assertSame(EnrichmentSchemaProposal::PROPOSED, $proposal->status);
        $this->assertSame(['metal', 'total_weight'], array_column($proposal->proposed['attributes'], 'key'), 'the attribute with an unknown dimension is left out');
        $this->assertStringContainsString('attribute shine', implode(' ', $proposal->problems));
        $this->assertSame(['yellow_gold', 'white_gold'], array_column($proposal->proposed['attributes'][0]['values'], 'key'), 'the value nothing in the sample says is gone');
        $this->assertSame([['kind' => 'value', 'key' => 'metal.rose_gold', 'by' => 'code']], array_map(fn (array $d): array => array_diff_key($d, ['why' => 1]), $proposal->review['dropped']),
            'removed by code before the review: no pattern reads it, so the reviewer never had to');
        $this->assertStringNotContainsString('rose_gold', $this->calls[1]['user'], 'the reviewer sees what is left');
        $this->assertSame(0.6, $proposal->coverage['attributes']['metal']['values']['yellow_gold'], '3 of 5 products');
        $this->assertEquals(1.0, $proposal->coverage['attributes']['total_weight']['share'], 'the weight is read in code from every description');
        $this->assertSame(0.6, $proposal->coverage['product_types']['ring']);
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'every call is accounted');
    }

    public function test_writer_and_reviewer_of_one_family_write_nothing(): void
    {
        Settings::set('enrichment.schema_reviewer_provider', 'openai');
        $proposal = app(DiscoverSchema::class)->handle($this->shop->id)['proposal'];

        $run = app(ProposeSchema::class)->handle($proposal);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame([], $this->calls);
        $this->assertSame(EnrichmentSchemaProposal::FAILED, $proposal->refresh()->status);
    }

    public function test_the_operator_approves_it_into_the_shops_vocabulary_on_the_shops_page(): void
    {
        $proposal = app(DiscoverSchema::class)->handle($this->shop->id)['proposal'];
        $this->replies = [['vocabulary' => $this->vocabulary()], ['verdict' => 'good', 'drop' => []]];
        app(ProposeSchema::class)->handle($proposal);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $this->withSession(['operator.shop' => $this->shop->id])->get('/operator/enrichment/schema')->assertOk()
            ->assertSee('total_weight')->assertSee('60%')->assertSee('gpt-5.4-mini')->assertSee('claude-sonnet-5-5')->assertSee('agents?agent=enrichment.schema_discovery', false);
        $this->get('/operator/agents?agent=enrichment.schema_discovery')->assertOk()->assertSee('agent-enrichment-schema_discovery', false);

        app(DecideSchemaProposal::class)->handle($proposal->refresh(), true);

        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole());
        $this->assertSame('jewelry_store', $vocabulary->key);
        $this->assertSame(EnrichmentSchemaProposal::APPROVED, $proposal->refresh()->status);
        $this->assertSame($vocabulary->id, $proposal->vocabulary_id);
        $this->assertTrue(Features::enabled('enrichment.read_text_in_code', $this->shop->id), 'and it is read from the store\'s own words');
        $this->assertNull(app(DecideSchemaProposal::class)->handle($proposal, false), 'decided once');
    }

    public function test_with_the_shops_flag_code_reads_the_vocabulary_from_its_fields_title_and_description(): void
    {
        $vocabulary = $this->vocabulary();
        array_pop($vocabulary['attributes']);
        $vocabulary += ['schema_version' => 1, 'root_category_external_id' => '*'];
        $vocabulary['image']['never'] = ['total_weight'];
        app(ImportVocabulary::class)->handle($this->shop->id, $vocabulary, 'test');
        $ring = fn (): array => $this->inShop(fn () => EnrichmentFact::query()->whereHas('product', fn ($q) => $q->where('external_id', '1'))
            ->where('status', '!=', FactStatus::Superseded)->get()->keyBy(fn (EnrichmentFact $f): string => $f->kind->value.':'.$f->key)->all());

        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertArrayNotHasKey('choice:metal', $ring(), 'off by default: nothing new is read');

        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $facts = $ring();

        $this->assertSame('ring', $facts['type:type']->value_text);
        $this->assertSame(['source' => 'field'], $facts['type:type']->evidence, 'the store\'s own type field');
        $this->assertSame('yellow_gold', $facts['choice:metal']->value_text);
        $this->assertSame('field', $facts['choice:metal']->evidence['source'], 'from the tags, before the title or the description');
        $this->assertSame(0.95, $facts['choice:metal']->confidence);
        $this->assertEqualsWithDelta(0.0011, (float) $facts['spec:total_weight']->value_number, 1e-9, '1.1 grams, in kg');
        $this->assertSame('description', $facts['spec:total_weight']->evidence['source']);
        $this->assertSame(0.8, $facts['spec:total_weight']->confidence);
    }

    /** @return array<string, mixed> a vocabulary as a writer would return it, one attribute broken */
    private function vocabulary(): array
    {
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];

        return [
            'key' => 'jewelry_store',
            'name' => $label('תכשיטים', 'Jewelry'),
            'product_types' => [
                ['key' => 'ring', 'label' => $label('טבעת', 'Ring'), 'patterns' => ['^\s*טבע(?:ת|ות)']],
                ['key' => 'pendant', 'label' => $label('תליון', 'Pendant'), 'patterns' => ['^\s*תליו(?:ן|נים)']],
            ],
            'attributes' => [
                ['key' => 'metal', 'type' => 'enum', 'multiple' => true, 'label' => $label('מתכת', 'Metal'), 'values' => [
                    ['key' => 'yellow_gold', 'label' => $label('זהב צהוב', 'Yellow gold'), 'patterns' => ['זהב צהוב']],
                    ['key' => 'white_gold', 'label' => $label('זהב לבן', 'White gold'), 'patterns' => ['זהב לבן']],
                    ['key' => 'rose_gold', 'label' => $label('זהב אדום', 'Rose gold'), 'patterns' => ['זהב אדום']],
                ]],
                ['key' => 'total_weight', 'type' => 'number', 'label' => $label('משקל', 'Weight'), 'dimension' => 'mass', 'min' => 0.0001, 'max' => 0.5,
                    'title_capture' => ['pattern' => 'משקל התכשיט\s*(\d+(?:[.,]\d+)?)\s*גרם', 'group' => 1, 'factor' => 0.001]],
                ['key' => 'shine', 'type' => 'number', 'label' => $label('ברק', 'Shine'), 'dimension' => 'brightness', 'min' => 0, 'max' => 10],
            ],
            'image' => ['never' => ['total_weight', 'shine'], 'filter_confidence' => ['default' => 0.8]],
        ];
    }
}
