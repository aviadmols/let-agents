<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\BuildStructure;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first scan's structure step: the catalog divided into branches, a vocabulary for each, scored,
 * and let on or held. Done once; run again, nothing is asked again.
 */
final class BuildStructureTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    public int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Settings::set('enrichment.branch_min_products', 5, $this->shop->id);
        $rings = $this->category('r', 'טבעות');
        $pendants = $this->category('p', 'תליונים');
        foreach (['טבעת זהב צהוב', 'טבעת זהב לבן', 'טבעת סוליטר', 'טבעת וינטאג', 'טבעת פשוטה'] as $i => $title) {
            $this->product('r'.$i, $title, 'משקל התכשיט 3 גרם', [$rings]);
        }
        foreach (['תליון לב זהב', 'תליון עיגולים', 'תליון סוליטר', 'תליון וינטאג', 'תליון פשוט'] as $i => $title) {
            $this->product('p'.$i, $title, 'משקל התכשיט 2 גרם', [$pendants]);
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly BuildStructureTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls++;

                return new ModelReply(array_shift($this->test->replies) ?? [], 4000, 1500);
            }
        });
    }

    public function test_each_branch_is_written_scored_and_let_on_by_the_gate_and_a_second_run_asks_nothing(): void
    {
        Settings::set('enrichment.schema_pass_score', 50, $this->shop->id);
        Features::override('enrichment.schema_auto_advance', true, $this->shop->id);
        $this->replies = [...$this->branchReplies('pendant', 'תליון', '^\s*תליו(?:ן|נים)'), ...$this->branchReplies('ring', 'טבעת', '^\s*טבע(?:ת|ות)')];

        $run = app(BuildStructure::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(4, $this->calls, 'a writer and a reviewer for each of the two branches');
        $this->assertSame(['branch_p', 'branch_r'], $this->inShop(fn () => EnrichmentVocabulary::query()->orderBy('key')->pluck('key')->all()), 'one vocabulary each, in the shop');
        $this->assertSame(2, $run->output['approved']);
        $this->assertSame(0, $run->output['held']);
        $this->assertSame(0, $run->output['rest']);
        $this->assertStringContainsString('2', $run->summary());

        $this->calls = 0;
        $again = app(BuildStructure::class)->handle($this->shop->id);

        $this->assertSame(0, $this->calls, 'nothing is asked again');
        $this->assertSame(2, $again->output['already']);
    }

    public function test_with_the_gate_off_every_branch_waits_for_a_person_with_its_score(): void
    {
        Features::override('enrichment.schema_auto_advance', false, $this->shop->id);
        $this->replies = [...$this->branchReplies('pendant', 'תליון', '^\s*תליו(?:ן|נים)'), ...$this->branchReplies('ring', 'טבעת', '^\s*טבע(?:ת|ות)')];

        $run = app(BuildStructure::class)->handle($this->shop->id);

        $this->assertSame(0, $run->output['approved']);
        $this->assertSame(2, $run->output['held']);
        $this->assertSame(['below_pass', 'below_pass'], array_column($run->output['detail'], 'why'), 'the score is under the pass score of 80, and the gate is off anyway');
        $proposals = $this->inShop(fn () => EnrichmentSchemaProposal::query()->orderBy('branch_key')->get());
        $this->assertSame([EnrichmentSchemaProposal::PROPOSED, EnrichmentSchemaProposal::PROPOSED], $proposals->pluck('status')->all());
        $this->assertNotNull($proposals[0]->score, 'scored, for the operator to see');
        $this->assertSame(0, $this->inShop(fn () => EnrichmentVocabulary::query()->count()));
    }

    public function test_a_vocabulary_for_the_whole_catalog_is_left_alone_and_a_branch_the_shop_already_has_is_not_redone(): void
    {
        $whole = ImportVocabulary::template('jewelry');
        app(ImportVocabulary::class)->handle($this->shop->id, $whole, 'test');

        $run = app(BuildStructure::class)->handle($this->shop->id);

        $this->assertSame(0, $this->calls, 'no model is asked when one vocabulary already reads it all');
        $this->assertSame('whole_catalog_vocabulary', $run->output['skipped']);
    }

    public function test_the_structure_command_the_first_scan_runs_reaches_the_action(): void
    {
        $this->replies = [...$this->branchReplies('pendant', 'תליון', '^\s*תליו(?:ן|נים)'), ...$this->branchReplies('ring', 'טבעת', '^\s*טבע(?:ת|ות)')];

        $this->artisan('enrichment', ['step' => 'structure', 'target' => $this->shop->slug])->assertSuccessful();

        $this->assertSame(4, $this->calls, 'the command ran the structure, a writer and a reviewer for each branch');
    }

    /** The branches are written biggest first, equal sizes by category id: pendants (p) before rings (r).
     *
     * @return list<array<string, mixed>> the writer's reply and the reviewer's, for a branch of one product type */
    private function branchReplies(string $type, string $name, string $pattern): array
    {
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];

        return [
            ['vocabulary' => [
                'name' => $label($name, ucfirst($type)),
                'product_types' => [['key' => $type, 'label' => $label($name, ucfirst($type)), 'patterns' => [$pattern]]],
                'attributes' => [['key' => 'metal', 'type' => 'enum', 'importance' => 'required', 'why' => $label('המתכת קובעת מחיר', 'The metal sets the price'), 'label' => $label('מתכת', 'Metal'), 'values' => [
                    ['key' => 'yellow_gold', 'label' => $label('זהב צהוב', 'Yellow gold'), 'patterns' => ['זהב צהוב']],
                    ['key' => 'white_gold', 'label' => $label('זהב לבן', 'White gold'), 'patterns' => ['זהב לבן']],
                ]]],
            ], 'sources' => [], 'notes' => []],
            ['verdict' => 'good', 'drop' => [], 'missing' => [], 'notes' => []],
        ];
    }
}
