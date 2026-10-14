<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\DiscoverSchema;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Enrichment\Scanning\KnowledgeEvidence;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A vocabulary for one branch of the catalog: only that branch's products are counted, the writer is
 * told what the store's own guides say buyers should know, what a buyer must know is kept even when
 * no product says it, and code (not the model) says which branch the vocabulary is for.
 */
final class BranchSchemaTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, system: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();

        $rings = $this->category('r', 'טבעות');
        $pendants = $this->category('p', 'תליונים');
        foreach ([1 => 'טבעת זהב צהוב 14K', 2 => 'טבעת זהב לבן 18K', 3 => 'טבעת סוליטר משובצת יהלום', 4 => 'טבעת וינטאג'] as $id => $title) {
            $this->product((string) $id, $title, 'משקל התכשיט 3 גרם', [$rings]);
        }
        foreach ([5 => 'תליון לב זהב', 6 => 'תליון עיגולים', 7 => 'תליון סוליטר', 8 => 'תליון וינטאג'] as $id => $title) {
            $this->product((string) $id, $title, 'משקל התכשיט 2 גרם', [$pendants]);
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly BranchSchemaTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = compact('provider', 'model', 'system', 'user');

                return new ModelReply(array_shift($this->test->replies) ?? [], 4000, 1500);
            }
        });
    }

    public function test_only_the_branchs_products_are_counted_and_its_root_and_key_are_the_branchs_not_the_models(): void
    {
        $plan = $this->inShop(fn (): array => BranchPlan::of(minProducts: 3, splitPercent: 90, maxBranches: 5));
        $this->assertSame(['p', 'r'], array_column($plan['branches'], 'root'), 'two branches of 4: ties by id');
        $rings = $plan['branches'][1];

        $proposal = app(DiscoverSchema::class)->handle($this->shop->id, null, $rings)['proposal'];

        $this->assertSame('branch_r', $proposal->branch_key);
        $this->assertSame(4, $proposal->stats['sampled'], 'the 4 rings, not the pendants');
        $this->assertSame([], $this->calls);

        // The same branch again: nothing new. The other branch is a different proposal.
        $this->assertNull(app(DiscoverSchema::class)->handle($this->shop->id, null, $rings)['proposal']);
        $other = app(DiscoverSchema::class)->handle($this->shop->id, null, $plan['branches'][0])['proposal'];
        $this->assertNotNull($other);
        $this->assertSame('branch_p', $other->branch_key);
    }

    public function test_the_writer_is_told_the_branch_and_the_guides_and_what_a_buyer_must_know_is_kept_unread(): void
    {
        $this->article('g1', 'כל מה שצריך לדעת על טבעות יהלום', 'גוף');
        $this->article('g2', 'איך מנקים תליונים', 'גוף');
        $rings = $this->inShop(fn (): array => BranchPlan::of(minProducts: 3, splitPercent: 90, maxBranches: 5))['branches'][1];
        $proposal = app(DiscoverSchema::class)->handle($this->shop->id, null, $rings)['proposal'];
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        $this->replies = [
            ['vocabulary' => [
                'key' => 'whatever_the_model_says',
                'root_category_external_id' => '*',
                'name' => $label('טבעות', 'Rings'),
                'product_types' => [
                    ['key' => 'ring', 'tryon' => 'ring', 'label' => $label('טבעת', 'Ring'), 'patterns' => ['^\s*טבע(?:ת|ות)']],
                    ['key' => 'solitaire', 'tryon' => 'shoes', 'label' => $label('סוליטר', 'Solitaire'), 'patterns' => ['סוליטר']],
                ],
                'attributes' => [
                    ['key' => 'metal', 'type' => 'enum', 'importance' => 'required', 'why' => $label('המתכת קובעת מחיר', 'The metal sets the price'), 'label' => $label('מתכת', 'Metal'), 'values' => [
                        ['key' => 'yellow_gold', 'label' => $label('זהב צהוב', 'Yellow gold'), 'patterns' => ['זהב צהוב']],
                        ['key' => 'white_gold', 'label' => $label('זהב לבן', 'White gold'), 'patterns' => ['זהב לבן']],
                    ]],
                    // Nobody writes it, and a buyer needs it: kept, so it is asked for.
                    ['key' => 'certificate', 'type' => 'enum', 'importance' => 'required', 'why' => $label('תעודה קובעת ערך', 'A certificate sets the value'), 'label' => $label('תעודה', 'Certificate'), 'values' => [
                        ['key' => 'with_certificate', 'label' => $label('עם תעודה', 'With certificate'), 'patterns' => ['תעודה גמולוגית']],
                        ['key' => 'no_certificate', 'label' => $label('בלי תעודה', 'No certificate'), 'patterns' => ['ללא תעודה']],
                    ]],
                    // Nobody writes it and nobody needs it: dropped, as before.
                    ['key' => 'sparkle', 'type' => 'enum', 'importance' => 'vital', 'label' => $label('נצנוץ', 'Sparkle'), 'values' => [
                        ['key' => 'high', 'label' => $label('גבוה', 'High'), 'patterns' => ['נוצץ מאוד']],
                        ['key' => 'low', 'label' => $label('נמוך', 'Low'), 'patterns' => ['עמום']],
                    ]],
                ],
            ], 'sources' => [], 'notes' => []],
            ['verdict' => 'good', 'drop' => [], 'missing' => [], 'notes' => []],
        ];

        $run = app(ProposeSchema::class)->handle($proposal);
        $proposal->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $written = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['name' => 'טבעות', 'path' => 'טבעות', 'products' => 4], $written['branch']);
        $this->assertSame(['כל מה שצריך לדעת על טבעות יהלום'], array_column($written['knowledge']['guides'], 'title'), 'the guide about this branch, not the one about pendants');
        $this->assertStringContainsString('importance', $this->calls[0]['system']);
        $this->assertStringContainsString('tryon', $this->calls[0]['system']);
        $this->assertSame(['guides' => $written['knowledge']['guides']], ['guides' => json_decode($this->calls[1]['user'], true)['knowledge']['guides']], 'the reviewer reads the same guides');

        $this->assertSame(EnrichmentSchemaProposal::PROPOSED, $proposal->status);
        $vocabulary = $proposal->proposed;
        $this->assertSame('r', $vocabulary['root_category_external_id'], 'the branch is code\'s, whatever the model wrote');
        $this->assertSame('branch_r', $vocabulary['key']);
        $this->assertSame(['metal', 'certificate'], array_column($vocabulary['attributes'], 'key'));
        $this->assertContains('certificate', array_column($vocabulary['attributes'], 'key'), 'required and read by no product: kept, to be asked for');
        $this->assertNotContains('sparkle', array_column($vocabulary['attributes'], 'key'), 'read by nothing and asked for by no one: dropped by code');
        $this->assertSame('required', $vocabulary['attributes'][0]['importance']);
        $this->assertSame('ring', $vocabulary['product_types'][0]['tryon']);
        $this->assertArrayNotHasKey('tryon', $vocabulary['product_types'][1], 'shoes are not a try-on class: left off the type, which stays');
        $this->assertStringContainsString('is not clothes, jewelry or furniture', implode(' ', $proposal->problems));
    }

    public function test_the_reviewers_drops_can_take_only_an_importance_or_only_a_try_on_class(): void
    {
        $vocabulary = [
            'product_types' => [['key' => 'ring', 'tryon' => 'ring'], ['key' => 'cufflinks', 'tryon' => 'ring']],
            'attributes' => [['key' => 'metal', 'type' => 'enum', 'importance' => 'required', 'values' => []]],
        ];

        [$after, $done] = ProposeSchema::drop($vocabulary, [
            ['kind' => 'importance', 'key' => 'metal.importance', 'why' => 'a buyer does not decide by it'],
            ['kind' => 'tryon', 'key' => 'cufflinks.tryon', 'why' => 'cufflinks are not tried on'],
        ]);

        $this->assertSame(['metal'], array_column($after['attributes'], 'key'), 'the attribute stays');
        $this->assertArrayNotHasKey('importance', $after['attributes'][0]);
        $this->assertSame(['ring', 'cufflinks'], array_column($after['product_types'], 'key'), 'the type stays');
        $this->assertArrayNotHasKey('tryon', $after['product_types'][1]);
        $this->assertSame(['importance', 'tryon'], array_column($done, 'kind'));
    }

    public function test_the_knowledge_is_titles_only_with_the_stores_pages_apart_and_nothing_when_there_is_none(): void
    {
        $this->assertSame(['guides' => [], 'pages' => []], $this->inShop(fn (): array => KnowledgeEvidence::of()));

        $this->article('g1', 'איך בוחרים טבעת', 'גוף ארוך');
        $this->page('s1', 'משלוחים והחזרות', 'גוף');

        $all = $this->inShop(fn (): array => KnowledgeEvidence::of());
        $this->assertSame([['title' => 'איך בוחרים טבעת', 'kind' => 'post']], $all['guides']);
        $this->assertSame(['משלוחים והחזרות'], $all['pages']);
        $this->assertStringNotContainsString('גוף', json_encode($all, JSON_UNESCAPED_UNICODE), 'titles, never the text');

        $this->assertSame([], $this->inShop(fn (): array => KnowledgeEvidence::of(['root' => 'p', 'name' => 'תליונים']))['guides'], 'a guide about rings is no evidence for pendants');
    }
}
