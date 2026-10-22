<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\DiscoverSchema;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Actions\ScoreSchemaProposal;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Filament\Operator\Pages\SchemaDiscovery;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Prompts\VocabularyText;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Models\Run;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The kind of product (a book's genre, a jewel's style, a tool's job) is what shoppers ask by, and what
 * stores seldom write in so many words: a shopper in a bookshop asked "פנטזיה" and code found nothing,
 * because no description says the word. So each vocabulary carries one choice for the kind, read by a
 * model from the whole description (`read_by: model`); its values stay though no pattern matches them,
 * the pilot in code does not count it, and the reading answers it with the words that show it.
 */
final class KindOfProductTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, system: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    private const BOOKS = [
        '1' => ['הצל של הרוח', 'מותחן ספרותי על נער בברצלונה שמגלה ספר נשכח. כריכה רכה.'],
        '2' => ['שר הטבעות', 'מסע אפי בעולם של קסם, דרקונים ומלכים, אל ההר שבו נוצרה הטבעת. כריכה רכה.'],
        '3' => ['זיכרונות מהבית ההוא', 'ממואר אישי של סופרת על ילדותה בירושלים. כריכה קשה.'],
        '4' => ['קיצור תולדות האנושות', 'ספר עיון על ההיסטוריה של המין האנושי.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();

        foreach (self::BOOKS as $id => [$title, $description]) {
            $this->product((string) $id, $title, $description, [], ['payload' => [
                'title' => $title, 'description' => $description, 'product_type' => 'ספרים', 'tags' => [], 'categories' => [], 'attributes' => [], 'meta' => [],
            ]]);
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly KindOfProductTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = compact('provider', 'system', 'user');

                return new ModelReply(array_shift($this->test->replies) ?? [], 4000, 1500);
            }
        });
    }

    public function test_the_vocabulary_says_read_by_model_on_a_choice_only(): void
    {
        [$definition, $problems] = VocabularyDefinition::parse($this->vocabulary());
        $this->assertSame([], $problems);
        $this->assertTrue($definition->readByModel('genre'));
        $this->assertFalse($definition->readByModel('cover'));

        $wrong = $this->vocabulary();
        $wrong['attributes'][1]['read_by'] = 'code';
        $this->assertContains('attributes[1].read_by is "model", on a choice only', VocabularyDefinition::parse($wrong)[1]);

        $number = $this->vocabulary();
        $number['attributes'][] = ['key' => 'pages', 'type' => 'number', 'read_by' => 'model', 'label' => ['he' => 'עמודים', 'en' => 'Pages'], 'dimension' => 'length', 'min' => 1, 'max' => 3000];
        $this->assertContains('attributes[2].read_by is "model", on a choice only', VocabularyDefinition::parse($number)[1]);
    }

    public function test_the_kind_keeps_every_value_at_no_share_and_the_pilot_does_not_count_it(): void
    {
        $proposal = app(DiscoverSchema::class)->handle($this->shop->id)['proposal'];
        $this->replies = [
            ['vocabulary' => $this->vocabulary(), 'sources' => [], 'notes' => []],
            ['verdict' => 'good', 'drop' => [], 'missing' => [], 'notes' => []],
        ];

        app(ProposeSchema::class)->handle($proposal);
        $proposal->refresh();

        $this->assertSame(EnrichmentSchemaProposal::PROPOSED, $proposal->status);
        $this->assertStringContainsString('# The kind of product', $this->calls[0]['system'], 'the writer is asked for it');
        $this->assertStringContainsString('# The kind of product', $this->calls[1]['system'], 'the reviewer judges it on the store, not on the counts');
        $this->assertStringContainsString('"read_by":"model"', $this->calls[1]['user']);

        $attributes = collect($proposal->proposed['attributes'])->keyBy('key');
        $this->assertSame(['thriller', 'fantasy', 'romance', 'memoir', 'history', 'self_help'], array_column($attributes['genre']['values'], 'key'), 'no description says "פנטזיה", and fantasy stays');
        $this->assertEquals(0, $proposal->coverage['attributes']['genre']['values']['fantasy']);
        $this->assertSame('model', $proposal->coverage['attributes']['genre']['read_by']);
        // An ordinary choice is measured as before: a value nothing in the sample says goes.
        $this->assertSame(['hardcover', 'softcover'], array_column($attributes['cover']['values'], 'key'));
        $this->assertContains(['kind' => 'value', 'key' => 'cover.ebook', 'by' => 'code', 'round' => 0], array_map(fn (array $d): array => array_diff_key($d, ['why' => 1]), $proposal->review['dropped']));

        $scored = app(ScoreSchemaProposal::class)->measure($proposal);
        $this->assertSame(['cover'], $scored['parts']['pilot']['asked'], 'a model reads the kind, not code: the pilot does not count it');
        $this->assertSame(0.75, $scored['parts']['pilot']['fill'], 'three of four books say their cover; the genre code finds on few costs nothing');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Livewire::test(SchemaDiscovery::class)->assertSeeHtml('data-read-by-model')->assertSee(__('enrichment::ui.schema.read_by_model'))->assertSee('פנטזיה');
    }

    public function test_the_reader_sees_the_kinds_in_their_own_section(): void
    {
        $text = VocabularyText::render(VocabularyDefinition::fromTrusted($this->vocabulary()));

        $this->assertStringContainsString('## Kinds (`add`, judged from the text read as a whole)', $text);
        $this->assertStringContainsString('- `genre` (ז\'אנר), one or more of: `thriller` מתח | `fantasy` פנטזיה', $text);
        $this->assertStringContainsString('לפי התיאור כולו', $text, 'with its hint');
        $this->assertStringNotContainsString('`genre` (ז\'אנר), one of', $text, 'not among the choices code found');
        $this->assertStringContainsString('`cover` (כריכה), one of', $text);

        $without = $this->vocabulary();
        array_pop($without['attributes']);
        $this->assertStringNotContainsString('## Kinds', VocabularyText::render(VocabularyDefinition::fromTrusted($without)), 'a vocabulary without a kind reads as it did');
    }

    public function test_the_reading_answers_the_kind_with_the_words_that_show_it_and_the_review_checks_it_from_the_whole_text(): void
    {
        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, $this->vocabulary(), 'test')['vocabulary'];
        Features::override('enrichment.auto_approve', true, $this->shop->id);

        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::ProductExtraction, $vocabulary->id)['batch'];
        $this->assertSame(4, $batch->prompt_version);
        $this->assertSame(PromptLibrary::version(TaskType::ProductExtraction, $vocabulary->definition()), $batch->prompt_version);
        $this->assertStringContainsString('6. Kinds', $batch->system_prompt);
        $this->assertStringContainsString('## Kinds', $batch->system_prompt);

        $item = $this->inShop(fn () => $batch->items()->get()->sole(fn ($i): bool => $i->request['id'] === '2'));
        $this->import($batch, $item->custom_id, ['id' => '2', 'type' => null, 'specs' => [], 'yes' => [], 'uses' => [], 'add' => [
            ['key' => 'genre', 'value' => 'fantasy', 'quote' => 'מסע אפי בעולם של קסם, דרקונים ומלכים'],
            ['key' => 'genre', 'value' => 'cooking', 'quote' => 'מסע אפי בעולם של קסם'],
            ['key' => 'genre', 'value' => 'romance', 'quote' => 'סיפור אהבה גדול'],
        ]]);

        $fact = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $item->subject_id)->where('key', 'genre')->sole());
        $this->assertSame(FactKind::Choice, $fact->kind);
        $this->assertSame('fantasy', $fact->value_text);
        $this->assertSame(FactOrigin::Model, $fact->origin);
        $this->assertSame(FactStatus::AwaitingReview, $fact->status, 'a model alone: checked by a second one');
        $problems = $this->inShop(fn () => $item->fresh()->problems);
        $this->assertContains('invalid_value:genre', $problems, 'a kind not in the list');
        $this->assertContains('quote_not_found:genre', $problems, 'words the text does not hold');

        $review = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::FactReview, $vocabulary->id, 1, ['subject' => 'product'])['batch'];
        $this->assertSame(5, $review->prompt_version);
        $this->assertSame(PromptLibrary::version(TaskType::FactReview, $vocabulary->definition()), $review->prompt_version);
        $this->assertStringContainsString('`unsure` only when the text does not say what the product is about', $review->system_prompt);
        $this->assertStringContainsString('## Kinds', $review->system_prompt);

        $claim = $this->inShop(fn () => $review->items()->sole());
        $this->assertSame([['f1', 'choice', 'genre', 'fantasy', 'מסע אפי בעולם של קסם, דרקונים ומלכים']], $claim->request['claims']);
        $this->import($review, $claim->custom_id, ['id' => '2', 'verdicts' => ['f1' => 'ok']]);

        $this->assertSame(FactStatus::Approved, $this->inShop(fn () => $fact->fresh()->status), 'a fantasy novel that never says "פנטזיה" is found by it');
    }

    public function test_the_operator_proposes_for_one_shop_from_the_command_line_and_sees_the_kinds_before_approving(): void
    {
        // The shop reads its whole catalog with a vocabulary of another key: the proposal is its next version.
        $old = $this->vocabulary();
        $old['key'] = 'sela_books';
        array_pop($old['attributes']);
        app(ImportVocabulary::class)->handle($this->shop->id, $old, 'test');
        $this->replies = [
            ['vocabulary' => $this->vocabulary(), 'sources' => [], 'notes' => []],
            ['verdict' => 'good', 'drop' => [], 'missing' => [], 'notes' => []],
        ];

        $this->artisan('enrichment', ['step' => 'schema', 'target' => $this->shop->slug])
            ->expectsOutputToContain('genre (ז\'אנר): thriller מתח, fantasy פנטזיה')
            ->expectsOutputToContain('proposed')
            ->assertSuccessful();

        $proposal = $this->inShop(fn () => EnrichmentSchemaProposal::query()->latest()->first());
        $this->assertSame(EnrichmentSchemaProposal::PROPOSED, $proposal->status, 'not scored or gated: it waits for a person');
        $this->assertSame('sela_books', $proposal->proposed['key'], 'approved, it replaces the vocabulary instead of reading the catalog twice');

        $this->artisan('enrichment', ['step' => 'schema', 'target' => $this->shop->slug, '--branch' => 'branch_nope'])
            ->expectsOutputToContain('Branch not found')
            ->assertFailed();
    }

    /** A bookshop's vocabulary: an ordinary choice code reads, and the kind a model reads. */
    private function vocabulary(): array
    {
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];

        return [
            'schema_version' => 1,
            'key' => 'books',
            'name' => $label('ספרים', 'Books'),
            'root_category_external_id' => '*',
            'tryon' => false,
            'product_types' => [['key' => 'book', 'label' => $label('ספר', 'Book'), 'patterns' => ['ספר']]],
            'attributes' => [
                ['key' => 'cover', 'type' => 'enum', 'importance' => 'required', 'label' => $label('כריכה', 'Cover'), 'values' => [
                    ['key' => 'hardcover', 'label' => $label('כריכה קשה', 'Hardcover'), 'patterns' => ['כריכה קשה']],
                    ['key' => 'softcover', 'label' => $label('כריכה רכה', 'Softcover'), 'patterns' => ['כריכה רכה']],
                    ['key' => 'ebook', 'label' => $label('ספר דיגיטלי', 'E-book'), 'patterns' => ['ספר דיגיטלי']],
                ]],
                ['key' => 'genre', 'type' => 'enum', 'multiple' => true, 'importance' => 'recommended', 'read_by' => 'model',
                    'hint' => 'לפי התיאור כולו: על מה הספר ומה הוא, לא לפי מילה אחת',
                    'why' => $label('קוראים מחפשים ספר לפי הז\'אנר', 'Readers look for a book by its genre'), 'label' => $label('ז\'אנר', 'Genre'), 'values' => [
                        ['key' => 'thriller', 'label' => $label('מתח', 'Thriller'), 'patterns' => ['מותחן']],
                        ['key' => 'fantasy', 'label' => $label('פנטזיה', 'Fantasy')],
                        ['key' => 'romance', 'label' => $label('רומנטיקה', 'Romance')],
                        ['key' => 'memoir', 'label' => $label('ממואר', 'Memoir'), 'patterns' => ['ממואר']],
                        ['key' => 'history', 'label' => $label('היסטוריה', 'History')],
                        ['key' => 'self_help', 'label' => $label('עזרה עצמית', 'Self-help')],
                    ]],
            ],
            'image' => ['filter_confidence' => ['default' => 0.8], 'never' => []],
        ];
    }

    /** @param array<string, mixed> $output */
    private function import(EnrichmentBatch $batch, string $customId, array $output): Run
    {
        $contents = json_encode(['type' => 'header', 'batch_id' => $batch->id, 'model' => 'claude-haiku-4-5'])."\n"
            .json_encode(['custom_id' => $customId, 'output' => $output], JSON_UNESCAPED_UNICODE);

        return app(ImportTaskResults::class)->handle($this->inShop(fn () => $batch->fresh()), $contents);
    }
}
