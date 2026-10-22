<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The prompts that know the kind of product (product_extraction v4, fact_review v5) go only to a vocabulary
 * that has one. Every other vocabulary is sent what it was sent before, to the byte: the same prompt, so the
 * same input hashes, so none of its products is read or reviewed again and its shop pays nothing.
 *
 * The pinned hashes were made by the code before the kind existed, on this same catalog and vocabulary.
 */
final class KindPromptVersionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const EXTRACTION_PROMPT_HASH = 'bbd2caaf83a6a4f5dffcec9942fabea15b4feaed024ff1be44144a7a31e71c6c';

    private const EXTRACTION_INPUT_HASH = 'ff70cd62cb7c141e2858a253e41c9a64aa0fb9ceb60c5685fce74d26154c8eb6';

    private const REVIEW_PROMPT_HASH = '17003912ad022607cdb138157a190a4d7ac842299d040b6ce373a9195b16bb5b';

    private const REVIEW_INPUT_HASH = '09377fde1270ee5e9c5f10686a0a5b21419e1d85c97f790b721e3727e75ead49';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $this->product('1', 'שר הטבעות', 'מסע אפי בעולם של קסם, דרקונים ומלכים. כריכה רכה.', [], ['payload' => [
            'title' => 'שר הטבעות', 'description' => 'מסע אפי בעולם של קסם, דרקונים ומלכים. כריכה רכה.', 'product_type' => 'ספרים', 'tags' => [], 'categories' => [], 'attributes' => [], 'meta' => [],
        ]]);
    }

    public function test_a_vocabulary_without_a_kind_is_sent_what_it_was_sent_before(): void
    {
        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, $this->vocabulary(), 'test')['vocabulary'];

        $extraction = $this->batch(TaskType::ProductExtraction, $vocabulary);
        $this->assertSame(3, $extraction->prompt_version);
        $this->assertSame(self::EXTRACTION_PROMPT_HASH, $extraction->prompt_hash);
        $this->assertSame(self::EXTRACTION_INPUT_HASH, $this->inShop(fn () => $extraction->items()->sole())->input_hash, 'no product is read again');

        $this->waitingChoice($vocabulary);
        $review = $this->batch(TaskType::FactReview, $vocabulary);
        $this->assertSame(4, $review->prompt_version);
        $this->assertSame(self::REVIEW_PROMPT_HASH, $review->prompt_hash);
        $this->assertSame(self::REVIEW_INPUT_HASH, $this->inShop(fn () => $review->items()->sole())->input_hash, 'no fact is reviewed again');
    }

    public function test_a_vocabulary_with_a_kind_is_sent_the_prompts_that_know_it(): void
    {
        $data = $this->vocabulary();
        $data['attributes'][] = ['key' => 'genre', 'type' => 'enum', 'multiple' => true, 'importance' => 'recommended', 'read_by' => 'model',
            'label' => ['he' => 'ז\'אנר', 'en' => 'Genre'], 'values' => [
                ['key' => 'fantasy', 'label' => ['he' => 'פנטזיה', 'en' => 'Fantasy']],
                ['key' => 'thriller', 'label' => ['he' => 'מתח', 'en' => 'Thriller']],
            ]];
        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test')['vocabulary'];

        $extraction = $this->batch(TaskType::ProductExtraction, $vocabulary);
        $this->assertSame(4, $extraction->prompt_version);
        $this->assertStringContainsString('6. Kinds', $extraction->system_prompt);
        $this->assertNotSame(self::EXTRACTION_PROMPT_HASH, $extraction->prompt_hash);

        $this->waitingChoice($vocabulary);
        $review = $this->batch(TaskType::FactReview, $vocabulary);
        $this->assertSame(5, $review->prompt_version);
        $this->assertStringContainsString('## Kinds', $review->system_prompt);
    }

    private function batch(TaskType $type, EnrichmentVocabulary $vocabulary): EnrichmentBatch
    {
        $result = app(CreateTaskFile::class)->handle($this->shop->id, $type, $vocabulary->id, 1, $type === TaskType::FactReview ? ['subject' => 'product'] : []);
        $this->assertNotNull($result['batch'], (string) $result['run']->summary());

        return $result['batch'];
    }

    private function waitingChoice(EnrichmentVocabulary $vocabulary): void
    {
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->inShop(fn () => CatalogProduct::query()->sole())->id,
            'vocabulary_id' => $vocabulary->id, 'kind' => 'choice', 'key' => 'cover', 'value_text' => 'softcover', 'quote' => 'כריכה רכה',
            'origin' => 'model', 'status' => 'awaiting_review', 'status_reason' => 'model_only', 'input_hash' => 'x',
        ]));
    }

    /** @return array<string, mixed> */
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
                ]],
            ],
            'image' => ['filter_confidence' => ['default' => 0.8], 'never' => []],
        ];
    }
}
