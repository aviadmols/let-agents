<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\AdvanceSchemaProposal;
use App\Modules\Enrichment\Actions\DiscoverSchema;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Actions\ScoreSchemaProposal;
use App\Modules\Enrichment\Filament\Operator\Pages\SchemaDiscovery;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A proposed vocabulary gets a number from what code measures on a pilot and from the review, and the
 * gate lets it on to the full scan only when the shop allowed it, the number passes, and nothing hard
 * failed. The case that taught it: a vocabulary can read every title and still say nothing of what
 * a buyer must know.
 */
final class SchemaScoreTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    private EnrichmentSchemaProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $rings = $this->category('r', 'טבעות');
        foreach ([1 => 'טבעת זהב צהוב 14K', 2 => 'טבעת זהב לבן 18K', 3 => 'טבעת סוליטר', 4 => 'טבעת וינטאג'] as $id => $title) {
            $this->product((string) $id, $title, 'משקל התכשיט 3 גרם', [$rings]);
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly SchemaScoreTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return new ModelReply(array_shift($this->test->replies) ?? [], 4000, 1500);
            }
        });

        $branch = $this->inShop(fn (): array => BranchPlan::of(minProducts: 3, splitPercent: 90, maxBranches: 5))['branches'][0];
        $this->proposal = app(DiscoverSchema::class)->handle($this->shop->id, null, $branch)['proposal'];
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        $this->replies = [
            ['vocabulary' => [
                'name' => $label('טבעות', 'Rings'),
                'product_types' => [['key' => 'ring', 'tryon' => 'ring', 'label' => $label('טבעת', 'Ring'), 'patterns' => ['^\s*טבע(?:ת|ות)']]],
                'attributes' => [
                    ['key' => 'metal', 'type' => 'enum', 'importance' => 'required', 'why' => $label('המתכת קובעת מחיר', 'The metal sets the price'), 'label' => $label('מתכת', 'Metal'), 'values' => [
                        ['key' => 'yellow_gold', 'label' => $label('זהב צהוב', 'Yellow gold'), 'patterns' => ['זהב צהוב']],
                        ['key' => 'white_gold', 'label' => $label('זהב לבן', 'White gold'), 'patterns' => ['זהב לבן']],
                    ]],
                    ['key' => 'certificate', 'type' => 'enum', 'importance' => 'required', 'why' => $label('תעודה קובעת ערך', 'A certificate sets the value'), 'label' => $label('תעודה', 'Certificate'), 'values' => [
                        ['key' => 'with_certificate', 'label' => $label('עם תעודה', 'With certificate'), 'patterns' => ['תעודה גמולוגית']],
                        ['key' => 'no_certificate', 'label' => $label('בלי תעודה', 'No certificate'), 'patterns' => ['ללא תעודה']],
                    ]],
                ],
            ], 'sources' => [], 'notes' => []],
            ['verdict' => 'good', 'drop' => [], 'missing' => [], 'notes' => []],
        ];
        app(ProposeSchema::class)->handle($this->proposal);
        $this->proposal->refresh();
    }

    public function test_the_score_is_made_of_what_the_pilot_found_of_what_a_buyer_must_know_and_the_review(): void
    {
        $scored = app(ScoreSchemaProposal::class)->measure($this->proposal);

        // 4 rings: the type reads all (20); metal found on 2 of 4, the certificate on none: 2 of 8 asked (40 x 0.25 = 10);
        // nothing left out (15); reviewer good with nothing dropped or missing (25).
        $this->assertSame(['types' => 20.0, 'fill' => 10.0, 'form' => 15.0, 'review' => 25.0], array_diff_key($scored['parts'], ['pilot' => 1]));
        $this->assertSame(70, $scored['score']);
        $this->assertSame([], $scored['hard']);
        $this->assertSame(['metal', 'certificate'], $scored['parts']['pilot']['asked']);
        $this->assertSame(['metal' => 2, 'certificate' => 0], $scored['parts']['pilot']['found'], 'which of what a buyer must know no product says');
        $this->assertSame(4, $scored['parts']['pilot']['products']);

        $run = app(ScoreSchemaProposal::class)->handle($this->proposal);
        $this->proposal->refresh();
        $this->assertSame(70, $this->proposal->score);
        $this->assertNull($this->proposal->hard_failures);
        $this->assertStringContainsString('70', $run->summary());
    }

    public function test_the_gate_waits_for_the_pass_score_the_shops_say_so_and_no_hard_failure(): void
    {
        app(ScoreSchemaProposal::class)->handle($this->proposal);
        $this->proposal->refresh();
        $advance = app(AdvanceSchemaProposal::class);

        $this->assertSame(['outcome' => 'held', 'why' => 'below_pass'], $advance->handle($this->proposal), '70 of the 80 the shop asks');

        Settings::set('enrichment.schema_pass_score', 60, $this->shop->id);
        Features::override('enrichment.schema_auto_advance', false, $this->shop->id);
        $this->assertSame(['outcome' => 'held', 'why' => 'auto_off'], $advance->handle($this->proposal), 'passes, but the shop turned the gate off');
        $this->assertSame(EnrichmentSchemaProposal::PROPOSED, $this->proposal->refresh()->status);

        // A hard failure stops it whatever the score.
        $this->proposal->update(['hard_failures' => ['no_type_read']]);
        Features::override('enrichment.schema_auto_advance', true, $this->shop->id);
        $this->assertSame(['outcome' => 'held', 'why' => 'hard_failure'], $advance->handle($this->proposal->refresh()));

        $this->proposal->update(['hard_failures' => null]);
        $this->assertSame(['outcome' => 'approved', 'why' => 'passed'], $advance->handle($this->proposal->refresh()));
        $this->assertSame(EnrichmentSchemaProposal::APPROVED, $this->proposal->refresh()->status);
        $this->assertSame(1, $this->inShop(fn () => EnrichmentVocabulary::query()->where('key', 'branch_r')->count()), 'the vocabulary is the shop\'s, for its branch');
    }

    public function test_the_operator_sees_the_score_what_it_is_made_of_and_why_the_gate_held_it(): void
    {
        app(ScoreSchemaProposal::class)->handle($this->proposal);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);

        Livewire::test(SchemaDiscovery::class)
            ->assertSeeHtml('data-schema-score')
            ->assertSee('70')
            ->assertSee('מחכה לאישור (מתחת לציון המעבר)')
            ->assertSee('מה שקונה צריך לדעת, שנמצא בפיילוט')
            ->assertSee('ענף טבעות')
            ->assertSee('המתכת קובעת מחיר');
    }

    public function test_a_vocabulary_with_nothing_to_score_cannot_pass(): void
    {
        $this->proposal->update(['coverage' => ['product_types' => ['ring' => 0.0], 'attributes' => []], 'stats' => ['sample' => []] + $this->proposal->stats]);

        $scored = app(ScoreSchemaProposal::class)->measure($this->proposal->refresh());

        $this->assertEqualsCanonicalizing(['no_type_read', 'no_pilot'], $scored['hard']);

        $this->proposal->update(['proposed' => null]);
        $this->assertSame(['score' => 0, 'parts' => [], 'hard' => ['no_vocabulary']], app(ScoreSchemaProposal::class)->measure($this->proposal->refresh()));
        $this->assertSame(['outcome' => 'held', 'why' => 'no_score'], app(AdvanceSchemaProposal::class)->handle($this->proposal->refresh()), 'never scored: never advanced');
    }
}
