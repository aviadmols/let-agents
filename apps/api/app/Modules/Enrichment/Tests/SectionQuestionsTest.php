<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\CollectProviderBatches;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\RunNightlyModelReading;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentSectionQuestions;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Support\WrittenSectionQuestions;
use App\Modules\Enrichment\Tasks\SectionQuestionsTask;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\SectionQuestions;
use App\Modules\Retrieval\Models\RetrievalChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The questions each section of a page or post answers, written once per section text: short
 * sections and texts already done are not asked about, an answer is checked in code before it is
 * kept, and the index gets the questions by the hash of the text they were written from.
 */
final class SectionQuestionsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const RETURNS = 'אפשר להחזיר מוצר תוך 14 יום מיום קבלת המשלוח, כל עוד הוא באריזתו המקורית ולא נעשה בו שימוש. '
        .'טבעת שהוגדלה או הוקטנה לפי מידה אישית אינה ניתנת להחזרה. ההחזר הכספי יבוצע תוך 7 ימי עסקים.';

    private const SHIPPING = 'המשלוח לכל הארץ נמסר עד הבית תוך 3 עד 5 ימי עסקים מרגע ההזמנה. הזמנה שבוצעה עד השעה 12:00 יוצאת '
        .'באותו היום. אפשר גם לאסוף את ההזמנה בעצמכם מהחנות, בתיאום מראש ובלי תוספת תשלום.';

    private const GOOD = ['אפשר להחזיר טבעת שהוגדלה?', 'תוך כמה ימים אפשר להחזיר מוצר?', 'מתי מקבלים את ההחזר הכספי?', 'האם צריך את האריזה המקורית כדי להחזיר?'];

    public function test_requests_skip_short_and_done_sections_ask_each_text_once_and_are_fingerprinted(): void
    {
        $this->buildShop();
        $version = PromptLibrary::version(TaskType::SectionQuestions);
        $returns = $this->section('page-1', 'מדיניות החזרות', self::RETURNS);
        // The same text on another page is the same question.
        $this->section('page-2', 'שאלות נפוצות', self::RETURNS);
        $this->section('page-3', 'צור קשר', 'טלפון: 03-5555555');
        $done = $this->section('page-4', 'משלוחים', self::SHIPPING);
        $older = $this->section('page-5', 'משלוחים ישנים', self::SHIPPING.' עדכון.');
        $this->written($done->text_hash, $version);
        $this->written($older->text_hash, $version - 1);

        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::SectionQuestions)['batch'];
        $items = $this->inShop(fn () => $batch->items()->get()->keyBy(fn (EnrichmentBatchItem $item): string => $item->context['text_hash']));

        $this->assertSame($version, $batch->prompt_version);
        $this->assertNull($batch->vocabulary_id, 'a section is not read against a vocabulary');
        $this->assertStringContainsString('for the store\'s search only', $batch->system_prompt);
        $this->assertEqualsCanonicalizing([$returns->text_hash, $older->text_hash], $items->keys()->all(), 'not the short one, not the one done with this prompt, the same text once');

        $item = $items[$returns->text_hash];
        $this->assertSame(SectionQuestionsTask::fingerprint($returns->text_hash, $version), $item->input_hash);
        $this->assertSame(SectionQuestionsTask::SUBJECT, $item->subject_type);
        $this->assertStringStartsWith('sq-'.substr($returns->text_hash, 0, 16).'-', $item->custom_id);
        $this->assertSame(['id', 'title', 'text'], array_keys($item->request), 'the section and its id, nothing else');
        $this->assertSame(self::RETURNS, $item->request['text']);

        $this->assertNull(app(CreateTaskFile::class)->handle($this->shop->id, TaskType::SectionQuestions)['batch'], 'what was asked is not asked twice');
    }

    public function test_an_answer_is_kept_only_when_its_questions_come_from_the_section(): void
    {
        $this->buildShop();
        $section = $this->section('page-1', 'מדיניות החזרות', self::RETURNS);
        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::SectionQuestions)['batch'];
        $item = $this->inShop(fn () => $batch->items()->sole());
        $id = $item->request['id'];
        $task = app(SectionQuestionsTask::class);
        $apply = fn (array $output) => $this->inShop(fn () => $task->apply($batch, $item, $output, 'gpt-5.4-nano'));

        $this->assertSame(['wrong_id'], $apply(['id' => 'other', 'questions' => self::GOOD])->problems);
        $this->assertSame(['count'], $apply(['id' => $id, 'questions' => array_slice(self::GOOD, 0, 2)])->problems, 'two are too few');
        $this->assertSame(['length'], $apply(['id' => $id, 'questions' => [...self::GOOD, 'החזר?']])->problems);
        $this->assertSame(['price_or_contact'], $apply(['id' => $id, 'questions' => [...array_slice(self::GOOD, 0, 3), 'איפה טופס ההחזרה באתר www.shop.co.il?']])->problems);
        $this->assertSame(['price_or_contact'], $apply(['id' => $id, 'questions' => [...array_slice(self::GOOD, 0, 3), 'אפשר להחזיר בטלפון 03-5555555?']])->problems);
        $this->assertSame(['not_from_section'], $apply(['id' => $id, 'questions' => ['איזו פיצה הכי טעימה בעיר?', 'מה שעות הפתיחה של הסניף?', 'יש חניה ליד החנות?']])->problems, 'questions this section does not answer');
        $this->assertSame(0, $this->inShop(fn () => EnrichmentSectionQuestions::query()->count()), 'nothing rejected is kept');

        // A repeat in other quotes and spaces is one question; they are kept sorted.
        $outcome = $apply(['id' => $id, 'questions' => [...self::GOOD, '  אפשר   להחזיר טבעת שהוגדלה? ']]);
        $this->assertSame(ItemStatus::Applied, $outcome->status);
        $kept = $this->inShop(fn () => EnrichmentSectionQuestions::query()->sole());
        $sorted = self::GOOD;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $kept->questions);
        $this->assertSame($section->text_hash, $kept->text_hash);
        $this->assertSame('gpt-5.4-nano', $kept->model);

        // Written again for the same text, it replaces.
        $apply(['id' => $id, 'questions' => array_slice(self::GOOD, 1)]);
        $this->assertCount(3, $this->inShop(fn () => EnrichmentSectionQuestions::query()->sole())->questions);
    }

    public function test_the_index_gets_the_questions_by_the_hash_of_the_text(): void
    {
        $this->buildShop();
        $this->written(hash('sha256', 'b'), 1, ['שאלה שנייה על החזרות?', 'שאלה ראשונה על החזרות?', 'שאלה שלישית על החזרות?']);
        $this->written(hash('sha256', 'a'), 1, []);

        $providers = iterator_to_array(app()->tagged(SectionQuestions::TAG));
        $this->assertContainsOnlyInstancesOf(SectionQuestions::class, $providers);
        $this->assertCount(1, array_filter($providers, fn ($p): bool => $p instanceof WrittenSectionQuestions));

        $questions = $this->inShop(fn () => app(WrittenSectionQuestions::class)->questions($this->shop->id, [hash('sha256', 'a'), hash('sha256', 'b'), hash('sha256', 'unknown')]));

        $this->assertSame([hash('sha256', 'b')], array_keys($questions), 'a text with none is left out');
        $this->assertSame(['שאלה שנייה על החזרות?', 'שאלה ראשונה על החזרות?', 'שאלה שלישית על החזרות?'], $questions[hash('sha256', 'b')]);
    }

    public function test_the_night_hands_sections_to_the_batch_api_up_to_its_share_and_the_day_keeps_the_answers(): void
    {
        $this->buildShop();
        $provider = $this->batchProvider();
        $this->section('page-1', 'מדיניות החזרות', self::RETURNS);
        $this->section('page-2', 'משלוחים', self::SHIPPING);
        Settings::set('enrichment.section_questions_per_night', 1, $this->shop->id);

        $night = app(RunNightlyModelReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $night->status->value);
        $this->assertSame([1], array_map('count', $provider->submitted), 'one section: the night\'s share, though the catch-up allows more');
        $this->assertSame(TaskType::SectionQuestions, $this->inShop(fn () => EnrichmentBatch::query()->sole())->task);

        app(RunNightlyModelReading::class)->handle($this->shop->id);
        $this->assertSame([1, 1], array_map('count', $provider->submitted), 'the next night the other one, nothing twice');

        $provider->state = BatchState::ENDED;
        $collected = app(CollectProviderBatches::class)->handle($this->shop->id);

        $this->assertSame(2, $collected->output['collected']);
        $this->assertSame(1, $collected->output['applied'], 'the returns questions fit the returns section');
        $this->assertSame(1, $collected->output['rejected'], 'and not the shipping one');
        $this->assertSame(1, $this->inShop(fn () => EnrichmentSectionQuestions::query()->count()));

        Features::override('enrichment.section_questions', false, $this->shop->id);
        $this->section('page-3', 'החלפות', self::RETURNS.' החלפה בלבד.');
        app(RunNightlyModelReading::class)->handle($this->shop->id);
        $this->assertCount(2, $provider->submitted, 'off: no section is asked about');
    }

    private function section(string $externalId, string $title, string $text, int $position = 0): RetrievalChunk
    {
        return $this->inShop(fn () => RetrievalChunk::query()->create([
            'shop_id' => $this->shop->id,
            'source' => 'content',
            'source_id' => $externalId,
            'external_id' => $externalId,
            'title' => $title,
            'position' => $position,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
        ]));
    }

    /** @param list<string>|null $questions */
    private function written(string $textHash, int $version, ?array $questions = null): void
    {
        $this->inShop(fn () => EnrichmentSectionQuestions::query()->create([
            'shop_id' => $this->shop->id,
            'text_hash' => $textHash,
            'prompt_version' => $version,
            'questions' => $questions ?? self::GOOD,
            'model' => 'gpt-5.4-nano',
        ]));
    }

    private function batchProvider(): object
    {
        $provider = new class implements BatchChatModel
        {
            /** @var list<list<BatchRequest>> */
            public array $submitted = [];

            public string $state = BatchState::RUNNING;

            /** @var list<string> */
            public array $questions = [];

            public function supports(AiProviderName $provider): bool
            {
                return true;
            }

            public function submit(AiProviderName $provider, string $model, array $requests): string
            {
                $this->submitted[] = $requests;

                return 'pb-'.count($this->submitted);
            }

            public function state(AiProviderName $provider, string $batchId): BatchState
            {
                return new BatchState($this->state, 1, 1, 0);
            }

            public function results(AiProviderName $provider, string $model, string $batchId, ?string $system = null, array $sent = []): iterable
            {
                foreach ($this->submitted[(int) substr($batchId, 3) - 1] as $request) {
                    // The same questions about returns, whatever the section: right for one, wrong for the other.
                    yield new BatchResult($request->customId, new ModelReply(['id' => json_decode($request->user, true)['id'], 'questions' => $this->questions], 1000, 100));
                }
            }
        };
        $provider->questions = self::GOOD;
        $this->app->instance(BatchChatModel::class, $provider);

        return $provider;
    }
}
