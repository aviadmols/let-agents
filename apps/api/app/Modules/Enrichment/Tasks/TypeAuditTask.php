<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Enums\Verdict;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentTypeAudit;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Scanning\PictureText;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Scanning\TypeDoubt;
use App\Modules\Enrichment\Support\ReplySchema;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The type audit as a task: which products are asked about (the doubtful ones, TypeDoubt), what each question holds, and
 * how a verdict becomes facts. Asked in a batch or one by one (AuditProductTypes); the answers come back through the
 * same checks either way.
 *
 * The verdict is applied carefully, in code: a type of the bank at the threshold or above replaces code's type (the
 * code fact is rejected with the verdict on it, so a re-reading rejects the same claim again without asking; a model
 * fact with the reason takes its place); "none" at the threshold takes code's type off and leaves the product untyped,
 * which is honest; under the threshold nothing changes and the verdict waits for the operator. Never a type the bank
 * does not have. What the shop's team decided stands.
 */
final class TypeAuditTask implements AgentTask
{
    public const SUBJECT = 'product';

    public const PROMPT = 'type_audit';

    /** A verdict is four short fields; the rest is thinking the provider keeps. */
    public const MAX_OUTPUT_TOKENS = 400;

    /** A bulk role: adaptive thinking at a low effort on a model that takes it. */
    public const EFFORT = 'low';

    /** Reasons of the facts the audit writes, and of the code facts it rejects. */
    public const REASON = 'audit';

    public const REASON_NONE = 'audit_none';

    public const REASON_OTHER = 'audit_other';

    public function type(): TaskType
    {
        return TaskType::TypeAudit;
    }

    /** Every doubtful product of the shop, each a question; one answered already for the same question is not asked again unless the batch says so. */
    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $force = (bool) ($batch->scope['force'] ?? false);
        $count = 0;
        foreach (self::doubts() as $doubt) {
            $stored = EnrichmentTypeAudit::query()->where('product_id', $doubt['product']->id)->where('fingerprint', $doubt['fingerprint'])->first();
            if (! $force && $stored !== null && in_array($stored->status, EnrichmentTypeAudit::ANSWERED, true)) {
                continue;
            }
            yield self::request($doubt);
            if (++$count >= $limit) {
                return;
            }
        }
    }

    /** @param array{product: CatalogProduct, fact: EnrichmentFact, bank: VocabularyDefinition, doubt: array<string, mixed>, fingerprint: string, request: array<string, mixed>} $doubt */
    public static function request(array $doubt): TaskRequest
    {
        return new TaskRequest(
            customId: 'ta-'.$doubt['product']->external_id.'-'.substr($doubt['fingerprint'], 0, 10),
            subjectType: self::SUBJECT,
            subjectId: (string) $doubt['product']->id,
            inputHash: $doubt['fingerprint'],
            request: $doubt['request'],
            context: ['code_type' => $doubt['doubt']['type'], 'reasons' => $doubt['doubt']['reasons'], 'types' => array_keys(TypeDoubt::patterns($doubt['bank']))],
        );
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        $product = CatalogProduct::query()->find($item->subject_id);
        if ($product === null || $product->removed_at !== null) {
            return true;
        }
        // the same question still: the product's words, its filing, the bank and the type code gave are as they were asked
        $current = self::doubtOf($product);

        return $current === null || $current['fingerprint'] !== $item->input_hash;
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        $audit = EnrichmentTypeAudit::query()->firstOrNew(['product_id' => $item->subject_id, 'fingerprint' => $item->input_hash]);
        $audit->shop_id = $batch->shop_id;
        $audit->batch_id = $batch->id;
        $audit->code_type ??= (string) ($item->context['code_type'] ?? '');
        $audit->reasons ??= (array) ($item->context['reasons'] ?? []);
        $audit->model = mb_substr($model, 0, 120);

        // Held to the schema in code whatever the provider promised; a reply that breaks it is not mended and not asked again here.
        $problems = ReplySchema::problems($output, ReplySchema::load(self::PROMPT, PromptLibrary::CURRENT[self::PROMPT]));
        if ($problems !== []) {
            return self::rejected($audit, ['schema', ...array_map(fn (string $p): string => mb_substr($p, 0, 120), $problems)]);
        }
        if ((string) $output['id'] !== (string) ($item->request['id'] ?? '')) {
            return self::rejected($audit, ['wrong_id']);
        }
        $type = trim((string) $output['type']);
        $known = array_map('strval', (array) ($item->context['types'] ?? []));
        if ($type !== EnrichmentTypeAudit::NONE && ! in_array($type, $known, true)) {
            // never a type the bank does not have
            return self::rejected($audit, ['unknown_type:'.mb_substr($type, 0, 60)]);
        }
        $confidence = (int) $output['confidence'];
        if ($confidence < 0 || $confidence > 100) {
            return self::rejected($audit, ['confidence_out_of_range:'.$confidence]);
        }

        $status = self::decide($audit, $type, $confidence, mb_substr(trim((string) $output['why']), 0, 1000), $audit->model, (int) Settings::get('enrichment.type_audit_min_confidence', $batch->shop_id));

        return new ItemOutcome(ItemStatus::Applied, in_array($status, [EnrichmentTypeAudit::APPLIED, EnrichmentTypeAudit::REMOVED], true) ? 1 : 0, 0, [$status]);
    }

    /**
     * A verdict made facts, carefully, and kept on the audit row: the same call applies a stored verdict again after a
     * re-reading wrote code's type anew, without asking. Returns the row's status.
     */
    public static function decide(EnrichmentTypeAudit $audit, string $type, int $confidence, string $why, ?string $model, int $threshold): string
    {
        return DB::transaction(function () use ($audit, $type, $confidence, $why, $model, $threshold): string {
            $productId = (string) $audit->product_id;
            $fact = EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Type)->where('origin', FactOrigin::Code)
                ->whereNotIn('status', [FactStatus::Superseded, FactStatus::Rejected])->whereNull('decided_by')->orderByDesc('id')->first();
            $codeType = $fact?->value_text ?? $audit->code_type;
            $team = EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Type)->where('origin', FactOrigin::Person)
                ->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable])->exists();
            $problems = [];

            $status = match (true) {
                $confidence < $threshold => EnrichmentTypeAudit::HELD,
                $team => EnrichmentTypeAudit::HELD,
                $type === (string) $codeType => EnrichmentTypeAudit::KEPT,
                $type === EnrichmentTypeAudit::NONE => EnrichmentTypeAudit::REMOVED,
                default => EnrichmentTypeAudit::APPLIED,
            };
            if ($team && $confidence >= $threshold) {
                // what the shop's team said of the type stands, whatever the model saw
                $problems[] = 'team_decided';
            }

            if ($status === EnrichmentTypeAudit::KEPT && $fact !== null) {
                $fact->forceFill(['review_verdict' => Verdict::Ok, 'review_model' => $model])->save();
            }
            if ($status === EnrichmentTypeAudit::REMOVED || $status === EnrichmentTypeAudit::APPLIED) {
                // the code claim is wrong, with the verdict on it: read again from the same filing, it is rejected again (ReadProductsInCode keeps verdicts)
                $fact?->forceFill(['status' => FactStatus::Rejected, 'status_reason' => $status === EnrichmentTypeAudit::REMOVED ? self::REASON_NONE : self::REASON_OTHER, 'review_verdict' => Verdict::Wrong, 'review_model' => $model])->save();
                // an earlier verdict's type, when the question was asked again
                EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Type)->where('origin', FactOrigin::Model)->where('status_reason', self::REASON)
                    ->where('status', '!=', FactStatus::Superseded)->whereNull('decided_by')->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);
            }
            if ($status === EnrichmentTypeAudit::APPLIED) {
                EnrichmentFact::query()->create([
                    'shop_id' => $audit->shop_id,
                    'product_id' => $productId,
                    'vocabulary_id' => $fact?->vocabulary_id ?? EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->value('id'),
                    'kind' => FactKind::Type,
                    'key' => 'type',
                    'value_text' => $type,
                    'quote' => mb_substr($why, 0, 500),
                    'origin' => FactOrigin::Model,
                    'status' => Features::enabled('enrichment.auto_approve', (string) $audit->shop_id) ? FactStatus::Approved : FactStatus::NeedsPerson,
                    'status_reason' => self::REASON,
                    'input_hash' => $audit->fingerprint,
                    'model' => $model,
                    'confidence' => $confidence / 100,
                    'evidence' => ['source' => self::REASON, 'code_type' => $codeType, 'reasons' => $audit->reasons, 'why' => $why],
                    'review_verdict' => Verdict::Ok,
                    'review_model' => $model,
                ]);
            }

            $audit->forceFill([
                'type' => $type,
                'confidence' => $confidence,
                'why' => $why,
                'model' => $model,
                'status' => $status,
                'problems' => $problems === [] ? null : $problems,
                'answered_at' => $audit->answered_at ?? now(),
            ])->save();

            return $status;
        });
    }

    /**
     * Every product whose type code cannot vouch for (TypeDoubt), with the question for each and its fingerprint. The
     * facts code wrote about types, one a product, each with the bank it was read by; a claim a verdict or a person
     * decided is left alone.
     *
     * @return list<array{product: CatalogProduct, fact: EnrichmentFact, bank: VocabularyDefinition, doubt: array<string, mixed>, fingerprint: string, request: array<string, mixed>}>
     */
    public static function doubts(?int &$typed = null): array
    {
        $typed = 0;
        $banks = [];
        foreach (EnrichmentVocabulary::query()->where('active', true)->get() as $vocabulary) {
            $banks[(string) $vocabulary->id] = $vocabulary->definition();
        }
        $pictures = PictureText::for();
        $out = [];
        foreach (self::codeTypeFacts()->with('product')->lazyById(500) as $fact) {
            $product = $fact->product;
            $bank = $banks[(string) $fact->vocabulary_id] ?? null;
            if ($product === null || $product->removed_at !== null || $bank === null) {
                continue;
            }
            $typed++;
            $doubt = self::doubtFor($product, $fact, $bank, isset($pictures[$product->id]) ? PictureText::text($pictures[$product->id]) : null);
            if ($doubt !== null) {
                $out[] = $doubt;
            }
        }

        return $out;
    }

    /** One product's doubt as it stands now, or null when its type stands: for the staleness of a question asked before. */
    public static function doubtOf(CatalogProduct $product): ?array
    {
        $fact = self::codeTypeFacts()->where('product_id', $product->id)->orderByDesc('id')->first();
        $vocabulary = $fact === null ? null : EnrichmentVocabulary::query()->find($fact->vocabulary_id);
        if ($fact === null || $vocabulary === null) {
            return null;
        }
        $pictures = PictureText::for([(string) $product->id]);

        return self::doubtFor($product, $fact, $vocabulary->definition(), isset($pictures[$product->id]) ? PictureText::text($pictures[$product->id]) : null);
    }

    /** @return Builder<EnrichmentFact> */
    private static function codeTypeFacts()
    {
        return EnrichmentFact::query()->where('kind', FactKind::Type)->where('origin', FactOrigin::Code)->whereNotNull('product_id')
            ->whereNotIn('status', [FactStatus::Superseded, FactStatus::Rejected])->whereNull('review_verdict')->whereNull('decided_by');
    }

    /** @return array{product: CatalogProduct, fact: EnrichmentFact, bank: VocabularyDefinition, doubt: array<string, mixed>, fingerprint: string, request: array<string, mixed>}|null */
    private static function doubtFor(CatalogProduct $product, EnrichmentFact $fact, VocabularyDefinition $bank, ?string $caption): ?array
    {
        $source = (string) ($fact->evidence['source'] ?? '');
        $source = $source !== '' ? $source : (str_starts_with((string) $fact->status_reason, 'code_') ? substr((string) $fact->status_reason, 5) : '');
        $doubt = TypeDoubt::of($product, $bank, (string) $fact->value_text, $source, $caption);
        if ($doubt === null) {
            return null;
        }
        $types = self::types($bank);

        return [
            'product' => $product,
            'fact' => $fact,
            'bank' => $bank,
            'doubt' => $doubt,
            'fingerprint' => self::fingerprint($product, $doubt, $types),
            'request' => [
                'id' => (string) $product->external_id,
                'title' => TextNormalizer::clean((string) $product->title),
                'caption' => $doubt['caption'],
                'filed' => $doubt['names'],
                'types' => $types,
                'code_type' => $doubt['type'],
                'read_from' => $doubt['source'] === TypeDoubt::FROM_TITLE ? 'title' : 'filing',
            ],
        ];
    }

    /**
     * The same question asked once: the title, the caption, the store's names for the product (sorted: a database's
     * order is no part of a hash), the bank's types, the type code gave, and the prompt's version.
     *
     * @param  array<string, mixed>  $doubt
     * @param  list<array{key: string, label: string, words: list<string>}>  $types
     */
    public static function fingerprint(CatalogProduct $product, array $doubt, array $types): string
    {
        $names = $doubt['names'];
        sort($names['categories'], SORT_STRING);
        sort($names['tags'], SORT_STRING);

        return hash('sha256', (string) json_encode([
            PromptLibrary::CURRENT[self::PROMPT],
            TextNormalizer::clean((string) $product->title),
            (string) $doubt['caption'],
            $names,
            hash('sha256', (string) json_encode($types, JSON_UNESCAPED_UNICODE)),
            $doubt['type'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * The bank's types as the auditor sees them: key, label and the words each is known by, in the bank's order.
     *
     * @return list<array{key: string, label: string, words: list<string>}>
     */
    public static function types(VocabularyDefinition $bank): array
    {
        $out = [];
        foreach ($bank->productTypes() as $type) {
            $label = (array) ($type['label'] ?? []);
            $out[] = [
                'key' => (string) $type['key'],
                'label' => (string) ($label['he'] ?? $label['en'] ?? $type['key']),
                'words' => array_values(array_map('strval', (array) ($type['words'] ?? []))),
            ];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $out;
    }

    /** @param list<string> $problems */
    private static function rejected(EnrichmentTypeAudit $audit, array $problems): ItemOutcome
    {
        $audit->forceFill(['status' => EnrichmentTypeAudit::REJECTED, 'problems' => $problems, 'answered_at' => now()])->save();

        return ItemOutcome::rejected($problems);
    }
}
