<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Settings;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentSectionQuestions;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalChunk;

/**
 * The questions a shopper might ask that one section of a page or post answers ("אפשר להחזיר
 * טבעת שהוגדלה?" for a returns section), so the index can find the section by a question that
 * shares none of its words (Retrieval's SectionQuestions, a second vector of the section).
 *
 * Written once per section text: the request is the section's title and text, its fingerprint the
 * hash of that text and the prompt's version, so an unchanged section is never asked about again and
 * the same text on many pages (a footer, a shipping note) is asked about once. A section too short
 * to answer anything is not asked about at all.
 *
 * Code rejects an answer with too few or too many questions, a question too short or too long, a
 * price, a link, an email or a phone number, or a question that shares no word with the section.
 *
 * Unlike a fact, these are never shown to a shopper and never cited: an answer rests on the
 * section's own text, and a weak question only makes the section a little easier to find. So they
 * are not sent to the other family's review; what passes the checks in code is kept as it is.
 */
final class SectionQuestionsTask implements AgentTask
{
    public const SUBJECT = 'section';

    private const QUESTIONS = [3, 6];

    private const QUESTION_CHARS = [8, 140];

    /** A section with less text than this answers nothing a shopper asks; a heading alone is not asked about. */
    private const MIN_TEXT_CHARS = 120;

    private const PRICE_OR_CONTACT = '~₪|(?<!\p{L})ש"ח|(?<!\p{L})שקל|\bnis\b|\$\s?\d|\d\s?\$|https?://|www\.|\S+@\S+|\+?\d{2,4}[\s-]?\d{3}[\s-]?\d{4}\b|\*\d{4}\b~iu';

    /** Words every question has: sharing one of these with the section says nothing about either. */
    private const QUESTION_WORDS = [
        'האם', 'איך', 'מתי', 'כמה', 'איפה', 'למה', 'מדוע', 'אפשר', 'ניתן', 'צריך', 'אני', 'אתם', 'שלי', 'שלכם', 'שלך', 'אין', 'אחרי', 'לפני',
        'the', 'and', 'can', 'how', 'what', 'when', 'where', 'why', 'does', 'are', 'you', 'your', 'for', 'with', 'will', 'there', 'this', 'that', 'which', 'have', 'has', 'any', 'from',
    ];

    /** Hebrew letters a word may be prefixed with ("והמשלוח", "בהזמנה"): taken off when matching. */
    private const PREFIXES = '/^[והבלמשכ]/u';

    private const FINAL_LETTERS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    public function type(): TaskType
    {
        return TaskType::SectionQuestions;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $includeDone = (bool) ($batch->scope['include_done'] ?? false);
        $version = (int) $batch->prompt_version;
        $count = 0;

        // What was written or asked already, read once rather than once a section.
        $written = $includeDone ? [] : array_flip(EnrichmentSectionQuestions::query()->where('prompt_version', $version)->pluck('text_hash')->all());
        $asked = $includeDone ? [] : array_flip(EnrichmentBatchItem::query()
            ->where('subject_type', self::SUBJECT)
            ->whereIn('status', [ItemStatus::Pending, ItemStatus::Applied, ItemStatus::Rejected])
            ->pluck('input_hash')->all());
        $seen = [];

        foreach (RetrievalChunk::query()->where('source', ChunkSource::Content->value)->lazyById(200) as $chunk) {
            $hash = (string) $chunk->text_hash;
            $inputHash = self::fingerprint($hash, $version);

            if (isset($seen[$hash]) || isset($written[$hash]) || isset($asked[$inputHash])) {
                continue;
            }

            $seen[$hash] = true;
            $text = TextNormalizer::clean((string) $chunk->text);

            if (mb_strlen($text) < self::MIN_TEXT_CHARS) {
                continue;
            }

            $text = mb_substr($text, 0, $maxChars);
            $id = substr($hash, 0, 12);

            yield new TaskRequest(
                customId: 'sq-'.substr($hash, 0, 16).'-'.substr($inputHash, 0, 10),
                subjectType: self::SUBJECT,
                subjectId: $chunk->id,
                inputHash: $inputHash,
                request: ['id' => $id, 'title' => TextNormalizer::clean((string) $chunk->title), 'text' => $text],
                // The questions are kept by the text's hash; the text is what they must share a word with.
                context: ['text_hash' => $hash, 'title' => (string) $chunk->title, 'text' => $text],
            );

            if (++$count >= $limit) {
                return;
            }
        }
    }

    /** Questions are kept by text, not by section: stale only when no section has that text any more. */
    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        return ! RetrievalChunk::query()
            ->where('source', ChunkSource::Content->value)
            ->where('text_hash', (string) ($item->context['text_hash'] ?? ''))
            ->exists();
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        if (! is_array($output['questions'] ?? null)) {
            return ItemOutcome::rejected(['questions']);
        }

        $hash = (string) $item->context['text_hash'];
        $questions = [];

        foreach ($output['questions'] as $question) {
            $question = is_string($question) ? (string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', $question)) : '';

            // The same question twice, in other quotes or case, is one question.
            if ($question !== '') {
                $questions[TextNormalizer::forMatching($question)] ??= $question;
            }
        }

        // A section that answers nothing a shopper asks is an answer, not a problem: what an older prompt wrote goes.
        if ($output['questions'] === []) {
            EnrichmentSectionQuestions::query()->where('text_hash', $hash)->delete();

            return new ItemOutcome(ItemStatus::Applied, 0, 0, []);
        }

        $questions = array_values($questions);
        $problem = $this->problem($questions, (string) ($item->context['title'] ?? '')."\n".(string) ($item->context['text'] ?? ''));

        if ($problem !== null) {
            return ItemOutcome::rejected([$problem]);
        }

        // Sorted, so the same questions always give the index the same text to embed.
        sort($questions, SORT_STRING);

        EnrichmentSectionQuestions::query()->updateOrCreate(
            ['shop_id' => $batch->shop_id, 'text_hash' => $hash],
            ['prompt_version' => (int) $batch->prompt_version, 'questions' => $questions, 'model' => $model],
        );

        return new ItemOutcome(ItemStatus::Applied, count($questions), 0, []);
    }

    /** The text hash and the prompt's version: the same text is asked about once per prompt. */
    public static function fingerprint(string $textHash, int $promptVersion): string
    {
        return hash('sha256', $textHash.'|'.TaskType::SectionQuestions->value.'.v'.$promptVersion);
    }

    /** @param list<string> $questions */
    private function problem(array $questions, string $section): ?string
    {
        $section = self::fold(TextNormalizer::forMatching($section));

        return match (true) {
            count($questions) < self::QUESTIONS[0] || count($questions) > self::QUESTIONS[1] => 'count',
            array_filter($questions, fn (string $q): bool => mb_strlen($q) < self::QUESTION_CHARS[0] || mb_strlen($q) > self::QUESTION_CHARS[1]) !== [] => 'length',
            array_filter($questions, fn (string $q): bool => preg_match(self::PRICE_OR_CONTACT, TextNormalizer::forMatching($q)) === 1) !== [] => 'price_or_contact',
            array_filter($questions, fn (string $q): bool => ! self::sharesAWord($q, $section)) !== [] => 'not_from_section',
            default => null,
        };
    }

    /**
     * Whether the question has at least one word of three letters or more that the section has too,
     * leniently: inside a longer word, without a Hebrew prefix letter, final letters as any other.
     */
    private static function sharesAWord(string $question, string $section): bool
    {
        preg_match_all('/\p{L}{3,}/u', TextNormalizer::forMatching($question), $words);

        foreach ($words[0] as $word) {
            if (in_array($word, self::QUESTION_WORDS, true)) {
                continue;
            }

            $word = self::fold($word);

            for ($i = 0; $i < 3 && mb_strlen($word) >= 3; $i++) {
                if (str_contains($section, $word)) {
                    return true;
                }

                if (preg_match(self::PREFIXES, $word) !== 1 || mb_strlen($word) < 4) {
                    break;
                }

                $word = mb_substr($word, 1);
            }
        }

        return false;
    }

    private static function fold(string $text): string
    {
        return strtr($text, self::FINAL_LETTERS);
    }
}
