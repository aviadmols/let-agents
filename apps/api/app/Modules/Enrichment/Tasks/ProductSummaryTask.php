<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\Boilerplate;
use App\Modules\Enrichment\Scanning\HighlightDigest;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Enrichment\Support\VocabularyBranch;
use Illuminate\Support\Collection;

/**
 * A short, clear description of a product for search: what it is, what it is for, where it fits,
 * written from its own text and approved facts, resting on quotes. It joins the product's meaning
 * vectors (FactNotes) once approved; the store's own description is never changed.
 *
 * Code rejects a summary whose quotes are not in the text, that has a number neither the text nor
 * a known fact has, a superlative the text does not make, praise, a price or contact details. What
 * passes waits for the reviewer of the other family, as every model-written fact does.
 */
final class ProductSummaryTask implements AgentTask
{
    public const SUBJECT = 'product';

    private const SUMMARY_CHARS = [20, 250];

    private const QUOTE_CHARS = [3, 300];

    private const MAX_QUOTES = 4;

    /** A product with less text than this beyond its title has nothing to say that its title does not. */
    private const MIN_TEXT_CHARS = 60;

    private const SUPERLATIVES = '~הכי\s+\S+|\S+\s+ביותר|מס(?:פר|\')\s*1|best|cheapest|number\s+one~iu';

    private const PRAISE = '~איכותי|מקצועי במיוחד|מושלמ|יוקרתי|מדהימ|premium|perfect|amazing|luxur~iu';

    private const PRICE_OR_CONTACT = '~₪|ש"ח|ש״ח|שקל|https?://|www\.|\S+@\S+|\b0\d{1,2}-?\d{7}\b~iu';

    public function __construct(private readonly FactWriter $facts) {}

    public function type(): TaskType
    {
        return TaskType::ProductSummary;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $vocabulary = $batch->vocabulary?->definition();

        if ($vocabulary === null) {
            return;
        }

        $maxChars = (int) Settings::get('enrichment.max_highlight_text_chars', $batch->shop_id);
        $includeDone = (bool) ($batch->scope['include_done'] ?? false);
        $count = 0;

        $boilerplate = Boilerplate::find((function () use ($vocabulary) {
            foreach (VocabularyBranch::products($vocabulary)->lazyById(200) as $product) {
                yield ProductDigest::sections($product);
            }
        })());

        $batch->forceFill(['scope' => ($batch->scope ?? []) + ['boilerplate' => $boilerplate]])->save();

        foreach (VocabularyBranch::products($vocabulary)->lazyById(200) as $product) {
            $reading = (array) (EnrichmentCodeReading::query()->where('product_id', $product->id)->value('reading') ?? []);
            $digest = HighlightDigest::build($product, $maxChars, $batch->prompt_hash, $boilerplate, HighlightDigest::known($this->approvedFacts($product->id), $vocabulary, $reading));

            if (mb_strlen((string) $digest->context['text']) - mb_strlen($product->title) < self::MIN_TEXT_CHARS) {
                continue;
            }

            if (! $includeDone && $this->alreadyWritten($product->id, $digest->inputHash)) {
                continue;
            }

            yield new TaskRequest(
                customId: 'sm-'.$product->external_id.'-'.substr($digest->inputHash, 0, 10),
                subjectType: self::SUBJECT,
                subjectId: $product->id,
                inputHash: $digest->inputHash,
                request: $digest->request,
                // What the summary may rest on: the text and the known facts, as the writer saw them.
                context: $digest->context + ['known' => (array) ($digest->request['known'] ?? [])],
            );

            if (++$count >= $limit) {
                return;
            }
        }
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        $product = CatalogProduct::query()->find($item->subject_id);

        if ($product === null || $product->removed_at !== null) {
            return true;
        }

        $maxChars = (int) Settings::get('enrichment.max_highlight_text_chars', $batch->shop_id);
        $digest = HighlightDigest::build($product, $maxChars, $batch->prompt_hash, (array) ($batch->scope['boilerplate'] ?? []), []);

        return $digest->context['text_hash'] !== ($item->context['text_hash'] ?? null);
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        $summary = (string) preg_replace('/^\s+|\s+$/u', '', (string) ($output['summary'] ?? ''));
        $quotes = array_values(array_filter(array_map(fn ($q): string => (string) preg_replace('/^\s+|\s+$/u', '', (string) $q), (array) ($output['quotes'] ?? [])), fn (string $q): bool => $q !== ''));

        // Nothing to say beyond the title is an answer, not a problem: nothing is written.
        if ($summary === '') {
            return new ItemOutcome(ItemStatus::Applied, 0, 0, []);
        }

        $problem = $this->problem($summary, $quotes, (string) ($item->context['text'] ?? ''), (array) ($item->context['known'] ?? []));

        if ($problem !== null) {
            return ItemOutcome::rejected([$problem]);
        }

        $this->facts->supersedePrevious('product_id', $item->subject_id, [FactKind::Summary]);
        $this->facts->write($batch, $item, 'product_id', $model, [
            'kind' => FactKind::Summary,
            'key' => 'summary',
            'value_text' => mb_substr($summary, 0, 255),
            // What it rests on, for the reviewer and for whoever asks later.
            'quote' => implode(' … ', array_slice($quotes, 0, self::MAX_QUOTES)),
            'origin' => FactOrigin::Model,
            'status' => FactStatus::AwaitingReview,
            'status_reason' => 'summary',
        ]);

        return new ItemOutcome(ItemStatus::Applied, 1, 0, []);
    }

    /**
     * @param  list<string>  $quotes
     * @param  list<string>  $known
     */
    private function problem(string $summary, array $quotes, string $text, array $known): ?string
    {
        $sources = $text."\n".implode("\n", $known);

        return match (true) {
            ! self::within($summary, self::SUMMARY_CHARS) => 'length',
            $quotes === [] || count($quotes) > self::MAX_QUOTES => 'quotes',
            array_filter($quotes, fn (string $q): bool => ! self::within($q, self::QUOTE_CHARS) || ! TextNormalizer::contains($text, $q)) !== [] => 'quote_not_in_text',
            preg_match(self::PRICE_OR_CONTACT, $summary) === 1 => 'price_or_contact',
            preg_match(self::PRAISE, $summary) === 1 && preg_match(self::PRAISE, $text) !== 1 => 'praise',
            self::unsaid('~\d+(?:[.,]\d+)?~u', $summary, $sources) => 'number_not_in_sources',
            self::unsaid(self::SUPERLATIVES, $summary, $text) => 'superlative_not_in_text',
            default => null,
        };
    }

    /** @param array{0: int, 1: int} $bounds */
    private static function within(string $value, array $bounds): bool
    {
        $length = mb_strlen($value);

        return $length >= $bounds[0] && $length <= $bounds[1];
    }

    /** Whether the summary says something matching $pattern that its sources do not. */
    private static function unsaid(string $pattern, string $summary, string $sources): bool
    {
        preg_match_all($pattern, $summary, $matches);

        foreach ($matches[0] as $match) {
            if (! TextNormalizer::contains($sources, $match)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, EnrichmentFact> */
    private function approvedFacts(string $productId): Collection
    {
        return EnrichmentFact::query()
            ->where('product_id', $productId)
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Spec, FactKind::Flag, FactKind::Tag, FactKind::Use, FactKind::Brand])
            ->orderBy('id')
            ->get();
    }

    private function alreadyWritten(string $productId, string $inputHash): bool
    {
        return EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Summary)->where('input_hash', $inputHash)->where('status', '!=', FactStatus::Superseded)->exists()
            || EnrichmentBatchItem::query()->where('subject_id', $productId)->where('input_hash', $inputHash)->whereIn('status', [ItemStatus::Pending, ItemStatus::Applied, ItemStatus::Rejected])->exists();
    }
}
