<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Support\FactNotes;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A short, clear description of each product for search: written from its own text and approved facts,
 * resting on quotes, checked in code, reviewed by the other family, and only then part of the product's
 * meaning vectors. The store's own description never changes.
 */
final class SearchSummaryTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TEXT = "טיק ברומזי מחורץ, עץ טרופי בגוון חם.\nשימושים ויישומים: מתאים לבניית דקים מעץ וחיפויי קיר ותקרה.\n"
        ."מומלץ להשתמש בברגים איכותיים המתאימים לעץ קשה. חשוב לשמור על מרווחי התפשטות בעת התקנת דקים.\nהאם מתאים לשימוש חיצוני? כן, מתאים לשימוש חוץ ופנים.";

    public function test_a_summary_rests_on_the_text_is_reviewed_and_then_joins_the_vectors(): void
    {
        $this->buildShop();
        $wood = $this->category('2212', 'עצים');
        $teak = $this->product('31538', 'טיק ברומזי 19x120 מ"מ מחורץ', self::TEXT, [$wood], ['payload' => ['description' => self::TEXT, 'categories' => [['id' => '2212', 'path' => ['עצים']]]]]);
        $pine = $this->product('31540', 'אורן מוקצע 19x95 מ"מ', self::TEXT, [$wood], ['payload' => ['description' => self::TEXT, 'categories' => [['id' => '2212', 'path' => ['עצים']]]]]);
        $this->product('31539', 'טיק ברומזי 19x95 מ"מ', 'טיק', [$wood]);

        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test')['vocabulary'];
        app(ReadProductsInCode::class)->handle($this->shop->id);

        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::ProductSummary, $vocabulary->id)['batch'];
        $items = $this->inShop(fn () => $batch->items()->get()->keyBy('subject_id'));

        $this->assertEqualsCanonicalizing([$teak->id, $pine->id], $items->keys()->all(), 'a product with nothing beyond its title gets no request');
        $this->assertStringStartsWith('sm-31538-', $items[$teak->id]->custom_id);
        $this->assertSame(PromptLibrary::version(TaskType::ProductSummary), $batch->prompt_version);
        $this->assertStringContainsString('for the store\'s search only', $batch->system_prompt);

        $good = ['id' => '31538', 'summary' => 'לוחות עץ טיק מחורצים לבניית דקים ולחיפוי קיר ותקרה, לשימוש בחוץ ובפנים, במידה 19x120 מ"מ.', 'quotes' => ['מתאים לבניית דקים מעץ וחיפויי קיר ותקרה', 'מתאים לשימוש חוץ ופנים']];
        $invented = ['id' => '31540', 'summary' => 'עץ אורן איכותי לדקים, עמיד 25 שנה בחוץ.', 'quotes' => ['מתאים לבניית דקים מעץ']];
        $contents = implode("\n", [
            json_encode(['type' => 'header', 'batch_id' => $batch->id]),
            json_encode(['custom_id' => $items[$teak->id]->custom_id, 'output' => $good], JSON_UNESCAPED_UNICODE),
            json_encode(['custom_id' => $items[$pine->id]->custom_id, 'output' => $invented], JSON_UNESCAPED_UNICODE),
        ]);
        app(ImportTaskResults::class)->handle($this->inShop(fn () => $batch->fresh()), $contents, 'gpt-5.4-nano');

        $rejected = $this->inShop(fn () => $items[$pine->id]->fresh());
        $this->assertSame(ItemStatus::Rejected, $rejected->status);
        $this->assertContains('number_not_in_sources', $rejected->problems, '"25 years" is said nowhere: not a fact');

        $summary = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $teak->id)->where('kind', FactKind::Summary)->sole());
        $this->assertSame($good['summary'], $summary->value_text);
        $this->assertSame('מתאים לבניית דקים מעץ וחיפויי קיר ותקרה … מתאים לשימוש חוץ ופנים', $summary->quote, 'what it rests on is kept');
        $this->assertSame(FactStatus::AwaitingReview, $summary->status);
        $before = $this->inShop(fn () => app(FactNotes::class)->notes('product', [$teak->id])[$teak->id] ?? []);
        $this->assertSame([], preg_grep('/^Summary:/u', $before), 'not in the search before its review');

        // The other family reviews it, with the prompt that says how, reading the text it was written from.
        $review = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::FactReview, $vocabulary->id, 1, ['subject' => 'product'])['batch'];
        $this->assertStringContainsString('`summary` claims', $review->system_prompt);
        $reviewItem = $this->inShop(fn () => $review->items()->where('subject_id', $teak->id)->sole());
        $this->assertStringContainsString('מרווחי התפשטות', $reviewItem->request['text']);

        $this->inShop(fn () => $summary->forceFill(['status' => FactStatus::Approved])->save());
        $notes = $this->inShop(fn () => app(FactNotes::class)->notes('product', [$teak->id])[$teak->id]);
        $this->assertSame('Summary: '.$good['summary'], $notes[0], 'approved, it leads what the vectors say');
        $this->assertSame(self::TEXT, $this->inShop(fn () => $teak->fresh()->description()), 'the store\'s own text is untouched');
    }
}
