<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BankGaps;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\CodeReading;
use App\Modules\Enrichment\Scanning\PatternMatch;
use App\Modules\Enrichment\Scanning\PictureText;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Collection;

/**
 * Bank completion: a store's attribute bank learns from its whole catalogue, not only the sample it was written from.
 *
 * 1. Code reads every product against the active bank (BankGaps): the products no type reads, and by type the phrases its
 *    products say (the store's attributes and variants, its tags, its pictures' kept descriptions) that no value reads.
 * 2. The writer (one family) proposes, from those gaps only, new types, new values of the bank's attributes and new
 *    attributes; a word for something already there is added to it (same_as). It never changes or removes anything.
 * 3. Code keeps what holds: each proposal valid on its own, reading products of the catalogue (MIN_PRODUCTS, or one where
 *    the store's own data says it), never taking from products what they had. A new value whose products are almost
 *    those of a value of the same attribute is that value (its patterns join it); one that only overlaps is a related
 *    value and stays ("sleeveless" beside "spaghetti straps").
 * 4. The auditor (the other family, checked in code before any call) judges a sample of the products each new type and
 *    value would tag, from the product's own words; a value with wrong tags is dropped.
 * 5. Every product is read again with the completed bank, in code, so no result depends on which products the bank was
 *    written from; the report compares before and after. Only with $activate, and only when something passed, the
 *    completed bank becomes a new active version (ImportVocabulary): the products are read with it (ReadProductsInCode)
 *    and the search index follows. Until then the active bank is untouched.
 *
 * Cost: one writer request per gaps batch and one auditor request per batch of AUDIT_BATCH items, each asking SpendGuard
 * first and recorded on the run. Reading products is code only, whatever their number.
 */
final class CompleteVocabulary
{
    public const AGENT = 'enrichment.bank_completer';

    public const ACTION = 'enrichment.complete_vocabulary';

    public const PROMPT_VERSION = 2;

    /** Products a new type or value must tag, unless the store's own data says it. */
    public const MIN_PRODUCTS = 2;

    /** Of a new value's products, this many judged by the auditor, at most. */
    public const AUDIT_SAMPLE = 8;

    /** A new type or value is kept when at least this share of its judged products is right. */
    public const MIN_ACCURACY = 0.8;

    /** Two values are one when the products of each are this much the products of both (Jaccard). */
    public const SAME_VALUE = 0.9;

    /** Of the products an attribute leaves with no value, this many read for a missed one. */
    public const MISSED_SAMPLE = 6;

    /** An attribute is checked for missed tags when it tags at least this share of the products it is about. */
    public const MISSED_MIN_SHARE = 0.1;

    private const AUDIT_BATCH = 60;

    private const WRITER_TOKENS = 8000;

    private const AUDIT_TOKENS = 4000;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /** @param  array<string, mixed>|null  $report  filled with what was found, kept, dropped and how products changed */
    public function handle(string $shopId, bool $activate = false, ?array &$report = null, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: function (RunContext $run) use ($shopId, $activate, &$report): void {
                $report = $this->tenant->run($shopId, fn (): array => $this->complete($run, $shopId, $activate));
            },
        );
    }

    /** @return array<string, mixed> */
    private function complete(RunContext $run, string $shopId, bool $activate): array
    {
        $vocabulary = EnrichmentVocabulary::query()->where('active', true)->latest('version')->first();
        if ($vocabulary === null) {
            $run->output(['outcome' => 'no_vocabulary'])->summary('enrichment::runs.completion_no_vocabulary');

            return ['outcome' => 'no_vocabulary'];
        }
        $before = $vocabulary->definition();
        $products = CatalogProduct::query()->whereNull('removed_at')->with('variants')->orderBy('id')->get();
        $pictures = PictureText::for();
        $brands = BrandResolver::forCatalog($products);
        $gaps = BankGaps::find($before, $products, $pictures, $brands);
        $report = ['products' => $products->count(), 'untyped_before' => array_sum(array_column($gaps['untyped'], 'products')),
            'gap_phrases' => array_sum(array_map('count', $gaps['phrases'])), 'costs' => []];

        if ($gaps['untyped'] === [] && $gaps['phrases'] === []) {
            $run->output($report + ['outcome' => 'complete'])->summary('enrichment::runs.completion_nothing');

            return $report + ['outcome' => 'complete'];
        }

        // the bank's own writer: the small reader proposed types and values on one run and none on the next (Me369, 18.11)
        $writer = AgentModel::from('enrichment.schema_writer_provider', 'enrichment.schema_writer_model');
        $auditor = AgentModel::from('enrichment.reviewer_provider', 'enrichment.reviewer_model');
        if ($writer->provider->family() === $auditor->provider->family()) {
            $run->fail('enrichment::runs.completion_same_family');

            return $report + ['outcome' => 'same_family'];
        }

        try {
            $input = $this->writerInput($before, $gaps);
            $proposed = $this->ask($run, $writer, 'schema_writer', 'bank_completion', $input, self::WRITER_TOKENS, $report);
            [$candidate, $added, $rejected] = self::merge($before, $proposed);
            // one round of correction: a new type whose patterns read less than half the untyped products it was proposed
            // for goes back to the writer with what it read and the titles it missed ("\bscarves?\b" never reads "scarf":
            // 3 of 15 scarves, Me369, 18.11)
            $feedback = $this->weakTypes($added, $candidate, $gaps, $products, $pictures, $brands);
            if ($feedback !== []) {
                $report['corrected'] = $feedback;
                $proposed = $this->ask($run, $writer, 'schema_writer', 'bank_completion', $input + ['feedback' => ['proposal' => $proposed, 'types_reading_too_little' => $feedback]], self::WRITER_TOKENS, $report);
                [$candidate, $added, $rejected] = self::merge($before, $proposed);
            }
            $report['proposal'] = $proposed;
            $report['proposed'] = ['types' => count((array) ($proposed['types'] ?? [])), 'values' => count((array) ($proposed['values'] ?? [])), 'attributes' => count((array) ($proposed['attributes'] ?? []))];

            // read every product with the candidate, and keep what reads products and takes nothing from them
            $after = $this->readAll($candidate, $products, $pictures, $brands);
            [$candidate, $added, $dropped] = $this->keepWhatHolds($before, $candidate, $added, $gaps['read'], $after, $products, $pictures, $brands);
            $rejected = [...$rejected, ...$dropped];

            // the auditor judges a sample of what each new thing tags
            $after = $this->readAll($candidate, $products, $pictures, $brands);
            [$audit, $wrong] = $this->audit($run, $auditor, $added, $gaps['read'], $after, $products, $pictures, $report);
            if ($wrong !== []) {
                [$candidate, $added] = self::without($candidate, $added, $wrong);
                foreach ($wrong as $item => $why) {
                    $rejected[] = ['item' => $item, 'why' => $why];
                }
                $after = $this->readAll($candidate, $products, $pictures, $brands);
            }
            // two values left that read the same products of a single choice hide each other there (read as none): the one
            // that tags fewer products alone goes, until none hides another
            while (($hidden = self::hidden($added, $gaps['read'], $after)) !== null) {
                [$candidate, $added] = self::without($candidate, $added, [$hidden => 'hides another value on the same products']);
                $rejected[] = ['item' => $hidden, 'why' => 'reads the same products as another value of the same choice, and fewer'];
                $after = $this->readAll($candidate, $products, $pictures, $brands);
            }
        } catch (SpendCapReached) {
            $run->output($report + ['outcome' => 'spend_cap'])->summary('enrichment::runs.completion_stopped');

            return $report + ['outcome' => 'spend_cap'];
        } catch (ModelCallFailed $e) {
            $run->fail('enrichment::runs.completion_failed', [], $e->getMessage());

            return $report + ['outcome' => 'failed'];
        }

        $report += ['added' => $added, 'rejected' => $rejected, 'audit' => $audit, 'changes' => self::changes($gaps['read'], $after)];
        $report['untyped_after'] = count(array_filter($after, fn (array $r): bool => ($r['type']['key'] ?? null) === null));
        // a check only, nothing written: of the products the completed bank leaves with no value of an attribute about their
        // kind, a sample the auditor reads for a value that was missed
        try {
            $report['missed'] = $this->missed($run, $auditor, $candidate, $after, $products, $pictures, $report);
        } catch (SpendCapReached|ModelCallFailed) {
            $report['missed'] = null;
        }

        $activated = null;
        if ($activate && $added !== []) {
            $imported = app(ImportVocabulary::class)->handle($shopId, $candidate->toArray(), 'bank_completion');
            $activated = $imported['vocabulary']?->version;
            if ($activated !== null) {
                app(ReadProductsInCode::class)->handle($shopId);
            }
        }
        $report['outcome'] = $added === [] ? 'nothing_kept' : ($activated !== null ? 'activated' : 'ready');
        $report['version'] = $activated;

        $run->output(array_diff_key($report, ['changes' => 1, 'proposal' => 1]) + ['changed_products' => $report['changes']['products']])->summary('enrichment::runs.completion_done', [
            'types' => (string) count(array_filter($added, fn (array $a): bool => $a['kind'] === 'type')),
            'values' => (string) count(array_filter($added, fn (array $a): bool => $a['kind'] !== 'type')),
            'products' => (string) $report['changes']['products'],
        ]);

        return $report;
    }

    /** @return array<string, mixed> */
    private function writerInput(VocabularyDefinition $bank, array $gaps): array
    {
        return [
            'language' => (string) (app(Shop::class)::query()->whereKey($this->tenant->require())->value('content_locale') ?: 'he'),
            'bank' => [
                'types' => array_map(fn (array $t): array => ['key' => $t['key'], 'label' => $t['label']['en'] ?? $t['key']], $bank->productTypes()),
                'attributes' => array_values(array_map(fn (array $a): array => ['key' => $a['key'], 'label' => $a['label']['en'] ?? $a['key'],
                    'values' => array_map(fn (array $v): array => ['key' => $v['key'], 'label' => $v['label']['en'] ?? $v['key']], (array) ($a['values'] ?? []))],
                    array_filter($bank->attributes(), fn (array $a): bool => ($a['type'] ?? null) === 'enum'))),
            ],
            'untyped' => array_map(fn (array $g): array => array_diff_key($g, ['ids' => 1]), array_slice($gaps['untyped'], 0, 60)),
            'phrases' => array_map(fn (string $type, array $list): array => ['type' => $type, 'phrases' => $list], array_keys($gaps['phrases']), array_values($gaps['phrases'])),
        ];
    }

    /**
     * The bank with what was proposed, each item only when the bank stays valid with it: a type or a value of an existing
     * attribute (or more patterns for what it is the same as), or a new attribute.
     *
     * @param  array<string, mixed>  $proposed
     * @return array{0: VocabularyDefinition, 1: list<array<string, mixed>>, 2: list<array{item: string, why: string}>}
     */
    public static function merge(VocabularyDefinition $bank, array $proposed): array
    {
        $data = $bank->toArray();
        $added = [];
        $rejected = [];
        $try = function (array $changed, array $item) use (&$data, &$added, &$rejected): void {
            [$valid, $problems] = VocabularyDefinition::parse($changed);
            if ($valid === null) {
                $rejected[] = ['item' => $item['label'], 'why' => mb_substr(implode('; ', $problems), 0, 200)];

                return;
            }
            $data = $changed;
            $added[] = $item;
        };
        $label = fn (array $p): array => ['he' => trim((string) ($p['label_he'] ?? '')) ?: (string) $p['key'], 'en' => trim((string) ($p['label_en'] ?? '')) ?: (string) $p['key']];
        $patterns = fn (array $p): array => array_values(array_filter(array_map('strval', (array) ($p['patterns'] ?? [])), fn (string $x): bool => trim($x) !== ''));

        foreach ((array) ($proposed['types'] ?? []) as $p) {
            $changed = $data;
            $same = trim((string) ($p['same_as'] ?? ''));
            $at = $same === '' ? null : array_search($same, array_column($changed['product_types'], 'key'), true);
            if ($at !== null && $at !== false) {
                $changed['product_types'][$at]['patterns'] = array_values(array_unique([...(array) ($changed['product_types'][$at]['patterns'] ?? []), ...$patterns($p)]));
                $try($changed, ['kind' => 'type_words', 'key' => $same, 'label' => 'type '.$same.' + '.implode(', ', $patterns($p)), 'patterns' => $patterns($p)]);

                continue;
            }
            $changed['product_types'][] = ['key' => (string) $p['key'], 'label' => $label($p), 'patterns' => $patterns($p)];
            $try($changed, ['kind' => 'type', 'key' => (string) $p['key'], 'label' => 'type '.$p['key'], 'patterns' => $patterns($p), 'groups' => array_map('strval', (array) ($p['groups'] ?? []))]);
        }

        foreach ((array) ($proposed['values'] ?? []) as $p) {
            $changed = $data;
            $at = array_search((string) ($p['attribute'] ?? ''), array_column($changed['attributes'] ?? [], 'key'), true);
            if ($at === false || ($changed['attributes'][$at]['type'] ?? null) !== 'enum') {
                $rejected[] = ['item' => ($p['attribute'] ?? '?').'.'.($p['key'] ?? '?'), 'why' => 'no such choice attribute'];

                continue;
            }
            $same = trim((string) ($p['same_as'] ?? ''));
            $values = (array) ($changed['attributes'][$at]['values'] ?? []);
            $vat = $same === '' ? false : array_search($same, array_column($values, 'key'), true);
            if ($vat !== false) {
                $changed['attributes'][$at]['values'][$vat]['patterns'] = array_values(array_unique([...(array) ($values[$vat]['patterns'] ?? []), ...$patterns($p)]));
                $try($changed, ['kind' => 'value_words', 'attribute' => $p['attribute'], 'key' => $same, 'label' => $p['attribute'].'.'.$same.' + '.implode(', ', $patterns($p)), 'patterns' => $patterns($p)]);

                continue;
            }
            $changed['attributes'][$at]['values'][] = ['key' => (string) $p['key'], 'label' => $label($p), 'patterns' => $patterns($p)];
            $try($changed, ['kind' => 'value', 'attribute' => (string) $p['attribute'], 'key' => (string) $p['key'], 'label' => $p['attribute'].'.'.$p['key'], 'patterns' => $patterns($p)]);
        }

        foreach ((array) ($proposed['attributes'] ?? []) as $p) {
            $changed = $data;
            $attribute = ['key' => (string) $p['key'], 'type' => 'enum', 'label' => $label($p), 'importance' => 'recommended',
                'visible' => (bool) ($p['visible'] ?? false),
                'values' => array_map(fn (array $v): array => ['key' => (string) $v['key'], 'label' => $label($v), 'patterns' => $patterns($v)], (array) ($p['values'] ?? []))];
            $types = array_values(array_intersect(array_map('strval', (array) ($p['applies_to'] ?? [])), array_column($changed['product_types'], 'key')));
            if ($types !== []) {
                $attribute['applies_to'] = $types;
            }
            $changed['attributes'][] = $attribute;
            $try($changed, ['kind' => 'attribute', 'key' => (string) $p['key'], 'label' => 'attribute '.$p['key'], 'values' => array_column($attribute['values'], 'key'), 'patterns' => array_merge(...array_map(fn (array $v): array => $v['patterns'], $attribute['values']))]);
        }

        return [VocabularyDefinition::fromTrusted($data), $added, $rejected];
    }

    /**
     * What of the candidate holds: a new type or value that tags products of the catalogue (MIN_PRODUCTS, or one where the
     * store's own data said it), takes no value or type from a product that had one, and is not another value of the
     * same attribute under a new name (then its patterns join that value).
     *
     * @return array{0: VocabularyDefinition, 1: list<array<string, mixed>>, 2: list<array{item: string, why: string}>}
     */
    private function keepWhatHolds(VocabularyDefinition $bank, VocabularyDefinition $candidate, array $added, array $before, array $after, Collection $products, array $pictures, BrandResolver $brands): array
    {
        $dropped = [];
        $data = $candidate->toArray();
        $kept = [];
        $byId = $products->keyBy(fn (CatalogProduct $p): string => (string) $p->id);
        foreach ($added as $item) {
            // each new thing judged alone, on the products whose words its patterns find: two proposals that both read one
            // product would otherwise hide each other (a single choice read twice is read as none)
            [$tagged, $lost] = self::alone($item, $candidate, $added, $before, $byId, $pictures, $brands);
            $min = $item['kind'] === 'type_words' || $item['kind'] === 'value_words' ? 1 : self::MIN_PRODUCTS;
            $fromStore = array_filter($tagged, fn (string $id): bool => in_array(self::sourceOf($item, $after[$id] ?? []), ['field', 'store_type', 'category', 'variant'], true));
            $repeats = $item['kind'] === 'attribute' ? self::repeats($item, $bank, $tagged, $before) : null;
            $reason = match (true) {
                $lost > 0 => "changes or takes what {$lost} products had",
                $repeats !== null => "repeats the attribute {$repeats}: its values belong there",
                count($tagged) < $min && $fromStore === [] => 'tags '.count($tagged).' products',
                default => null,
            };
            if ($reason === null && $item['kind'] === 'value') {
                $twin = self::twin($item, $after);
                if ($twin !== null) {
                    $data = self::joinValue($data, $item, $twin);
                    $kept[] = ['kind' => 'value_words', 'attribute' => $item['attribute'], 'key' => $twin, 'label' => $item['label'].' = '.$twin];

                    continue;
                }
            }
            if ($reason !== null) {
                $data = self::withoutItem($data, $item);
                $dropped[] = ['item' => $item['label'], 'why' => $reason];

                continue;
            }
            $kept[] = $item + ['products' => count($tagged), 'ids' => $tagged];
        }

        return [VocabularyDefinition::fromTrusted($data), $kept, $dropped];
    }

    /**
     * What one new thing tags and takes, alone in the bank (the other proposals left out), read only on the products whose
     * words its patterns find: the rest read as before.
     *
     * @param  Collection<string, CatalogProduct>  $byId
     * @return array{0: list<string>, 1: int}
     */
    private static function alone(array $item, VocabularyDefinition $candidate, array $added, array $before, Collection $byId, array $pictures, BrandResolver $brands): array
    {
        $data = $candidate->toArray();
        foreach ($added as $other) {
            if ($other !== $item && in_array($other['kind'], ['type', 'value', 'attribute'], true)) {
                $data = self::withoutItem($data, $other);
            }
        }
        $one = VocabularyDefinition::fromTrusted($data);
        $patterns = (array) ($item['patterns'] ?? []);
        $readings = [];
        foreach ($byId as $id => $product) {
            $picture = isset($pictures[$id]) ? PictureText::text($pictures[$id]) : '';
            $parts = [$product->title, $product->description(), (string) ($product->payload['product_type'] ?? ''), $picture, ...array_map('strval', (array) ($product->payload['tags'] ?? []))];
            foreach ($product->storeAttributes() as $a) {
                $parts[] = $a['name'].': '.implode(', ', (array) $a['values']);
            }
            $text = TextNormalizer::clean(implode(PHP_EOL, $parts));
            if ($patterns !== [] && PatternMatch::first($text, $patterns) !== null) {
                $readings[$id] = CodeReading::read($product, $brands, $one, readText: true, picture: $picture === '' ? null : $picture);
            }
        }

        return [self::tagged($item, $readings), self::lost($item, array_intersect_key($before, $readings), $readings)];
    }

    /**
     * The auditor's verdicts on a sample of what each new type and value tags, and the items to drop: those whose
     * judged products are right less than MIN_ACCURACY of the time.
     *
     * @return array{0: array<string, array{judged: int, correct: int, incorrect: int, unsure: int}>, 1: array<string, string>}
     */
    private function audit(RunContext $run, AgentModel $auditor, array $added, array $before, array $after, Collection $products, array $pictures, array &$report): array
    {
        $byId = $products->keyBy(fn (CatalogProduct $p): string => (string) $p->id);
        $items = [];
        foreach ($added as $item) {
            if ($item['kind'] === 'type_words' || $item['kind'] === 'value_words') {
                continue;
            }
            // what it tags on its own (keepWhatHolds), so a value another hides in the combined bank is judged all the same
            $new = array_values(array_filter((array) ($item['ids'] ?? self::tagged($item, $after)), fn (string $id): bool => ! in_array($id, self::tagged($item, $before), true)));
            usort($new, fn (string $a, string $b): int => strcmp(md5($a), md5($b)));
            foreach (array_slice($new, 0, self::AUDIT_SAMPLE) as $id) {
                $product = $byId->get($id);
                if ($product !== null) {
                    $items[] = ['id' => count($items).'', 'item' => $item['label'], 'product' => self::described($product, $pictures[$id] ?? null),
                        'tag' => self::tagOf($item, $after[$id] ?? [])];
                }
            }
        }

        $verdicts = [];
        foreach (array_chunk($items, self::AUDIT_BATCH) as $batch) {
            $reply = $this->ask($run, $auditor, 'reviewer', 'bank_audit', ['items' => array_map(fn (array $i): array => array_diff_key($i, ['item' => 1]), $batch)], self::AUDIT_TOKENS, $report);
            foreach ((array) ($reply['verdicts'] ?? []) as $v) {
                $verdicts[(string) ($v['id'] ?? '')] = (string) ($v['verdict'] ?? 'unsure');
            }
        }

        $audit = [];
        foreach ($items as $i) {
            $verdict = $verdicts[$i['id']] ?? 'unsure';
            $audit[$i['item']] ??= ['judged' => 0, 'correct' => 0, 'incorrect' => 0, 'unsure' => 0];
            $audit[$i['item']]['judged']++;
            $audit[$i['item']][$verdict] = ($audit[$i['item']][$verdict] ?? 0) + 1;
        }
        $wrong = [];
        foreach ($audit as $item => $a) {
            $sure = $a['correct'] + $a['incorrect'];
            if ($sure > 0 && $a['correct'] / $sure < self::MIN_ACCURACY) {
                $wrong[$item] = "the auditor found {$a['incorrect']} of {$sure} wrong";
            }
        }

        return [$audit, $wrong];
    }

    /**
     * The new types whose patterns, alone in the bank, read less than half the untyped products of the groups they were
     * proposed for, each with what it read and up to six titles it missed.
     *
     * @return list<array{type: string, patterns: list<string>, read: int, of: int, missed_titles: list<string>}>
     */
    private function weakTypes(array $added, VocabularyDefinition $candidate, array $gaps, Collection $products, array $pictures, BrandResolver $brands): array
    {
        $byId = $products->keyBy(fn (CatalogProduct $p): string => (string) $p->id);
        $groups = array_column($gaps['untyped'], 'ids', 'group');
        $out = [];
        foreach ($added as $item) {
            $wanted = [];
            foreach ((array) ($item['groups'] ?? []) as $group) {
                array_push($wanted, ...(array) ($groups[mb_strtolower((string) $group)] ?? []));
            }
            if ($item['kind'] !== 'type' || $wanted === []) {
                continue;
            }
            $wanted = array_values(array_unique($wanted));
            [$tagged] = self::alone($item, $candidate, $added, $gaps['read'], $byId, $pictures, $brands);
            $read = array_intersect($wanted, $tagged);
            if (count($read) * 2 < count($wanted)) {
                $out[] = ['type' => $item['key'], 'patterns' => (array) $item['patterns'], 'read' => count($read), 'of' => count($wanted),
                    'missed_titles' => array_slice(array_values(array_map(fn (string $id): string => (string) ($byId->get($id)?->title ?? ''), array_diff($wanted, $read))), 0, 6)];
            }
        }

        return $out;
    }

    /**
     * Missed tags, estimated: for each choice attribute of the completed bank that tags at least MISSED_MIN_SHARE of the
     * products it is about, a sample of those it left with no value (MISSED_SAMPLE), read by the auditor for a value
     * plainly true of them. Nothing is written: it says how many products each attribute likely misses.
     *
     * @return array<string, array{about: int, without: int, checked: int, missed: int, none: int, unsure: int, estimated_missed: int, examples: list<string>}>
     */
    private function missed(RunContext $run, AgentModel $auditor, VocabularyDefinition $bank, array $after, Collection $products, array $pictures, array &$report): array
    {
        $byId = $products->keyBy(fn (CatalogProduct $p): string => (string) $p->id);
        $items = [];
        $counts = [];
        foreach ($bank->attributes() as $attribute) {
            $key = (string) $attribute['key'];
            if (($attribute['type'] ?? null) !== 'enum' || ($attribute['read_by'] ?? null) === VocabularyDefinition::READ_BY_MODEL) {
                continue;
            }
            $about = array_keys(array_filter($after, fn (array $r): bool => $bank->appliesTo($key, $r['type']['key'] ?? null)));
            $without = array_values(array_filter($about, fn ($id): bool => ! isset($after[$id]['text_choices'][$key]) && ! isset($after[$id]['variant_choices'][$key]) && ! isset($after[$id]['category_choices'][$key])));
            if ($about === [] || (count($about) - count($without)) / count($about) < self::MISSED_MIN_SHARE || $without === []) {
                continue;
            }
            usort($without, fn ($a, $b): int => strcmp(md5((string) $a), md5((string) $b)));
            $labels = array_map(fn (array $v): string => (string) ($v['label']['en'] ?? $v['key']), (array) $attribute['values']);
            $counts[$key] = ['about' => count($about), 'without' => count($without), 'checked' => 0, 'missed' => 0, 'none' => 0, 'unsure' => 0, 'estimated_missed' => 0, 'examples' => []];
            foreach (array_slice($without, 0, self::MISSED_SAMPLE) as $id) {
                $product = $byId->get((string) $id);
                if ($product !== null) {
                    $items[] = ['id' => (string) count($items), 'key' => $key, 'title' => (string) $product->title, 'product' => self::described($product, $pictures[(string) $id] ?? null),
                        'attribute' => (string) ($attribute['label']['en'] ?? $key), 'values' => $labels];
                }
            }
        }
        foreach (array_chunk($items, self::AUDIT_BATCH) as $batch) {
            $reply = $this->ask($run, $auditor, 'reviewer', 'bank_recall', ['items' => array_map(fn (array $i): array => array_diff_key($i, ['key' => 1, 'title' => 1]), $batch)], self::AUDIT_TOKENS, $report);
            $said = array_column((array) ($reply['verdicts'] ?? []), 'value', 'id');
            foreach ($batch as $i) {
                $value = trim((string) ($said[$i['id']] ?? 'unsure'));
                $c = &$counts[$i['key']];
                $c['checked']++;
                if (in_array($value, $i['values'], true)) {
                    $c['missed']++;
                    if (count($c['examples']) < 3) {
                        $c['examples'][] = $i['title'].' → '.$value;
                    }
                } elseif ($value === 'none') {
                    $c['none']++;
                } else {
                    $c['unsure']++;
                }
                unset($c);
            }
        }
        foreach ($counts as $key => $c) {
            $counts[$key]['estimated_missed'] = $c['checked'] === 0 ? 0 : (int) round($c['without'] * $c['missed'] / $c['checked']);
        }

        return $counts;
    }

    /**
     * A new value that tags fewer products in the completed bank than alone, because another value of its choice reads the
     * same products: of the values so hidden, the one that tags fewest alone (its label). Null when none is.
     */
    private static function hidden(array $added, array $before, array $after): ?string
    {
        $short = [];
        foreach ($added as $item) {
            // hidden by another (fewer products than alone), or, together with the others, changing what a product had
            if (in_array($item['kind'], ['value', 'type'], true) && isset($item['ids']) && (count(self::tagged($item, $after)) < count($item['ids']) || self::lost($item, $before, $after) > 0)) {
                $short[$item['label']] = count($item['ids']);
            }
        }
        if ($short === []) {
            return null;
        }
        asort($short);

        return (string) array_key_first($short);
    }

    /**
     * @return array{0: VocabularyDefinition, 1: list<array<string, mixed>>}
     */
    private static function without(VocabularyDefinition $candidate, array $added, array $wrong): array
    {
        $data = $candidate->toArray();
        $kept = [];
        foreach ($added as $item) {
            if (isset($wrong[$item['label']])) {
                $data = self::withoutItem($data, $item);
            } else {
                $kept[] = $item;
            }
        }

        return [VocabularyDefinition::fromTrusted($data), $kept];
    }

    /** @return array<string, mixed> the reply's data */
    private function ask(RunContext $run, AgentModel $who, string $role, string $prompt, array $input, int $maxOutput, array &$report): array
    {
        $system = (string) file_get_contents(dirname(__DIR__).'/Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md');
        $schema = json_decode((string) file_get_contents(dirname(__DIR__).'/Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get("enrichment.{$role}_input_usd_per_million");
        $out = (float) Settings::get("enrichment.{$role}_output_usd_per_million");
        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $in + $maxOutput * $out) / 1_000_000);
        /** @var ModelReply $reply */
        $reply = $this->models instanceof StructuredChatModel && is_array($schema)
            ? $this->models->structured($who->provider, $who->model, $system, $user, $schema, $maxOutput)
            : $this->models->json($who->provider, $who->model, $system, $user, $maxOutput);
        $cost = $reply->costUsd($in, $out);
        $run->usage($who->provider->value, $who->model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
        $report['costs'][] = ['step' => $prompt, 'model' => $who->model, 'usd' => round($cost, 4)];

        return (array) $reply->data;
    }

    /** @return array<string, array<string, mixed>> each product's reading, by id */
    private function readAll(VocabularyDefinition $bank, Collection $products, array $pictures, BrandResolver $brands): array
    {
        $out = [];
        foreach ($products as $product) {
            $out[(string) $product->id] = CodeReading::read($product, $brands, $bank, readText: true, picture: isset($pictures[$product->id]) ? PictureText::text($pictures[$product->id]) : null);
        }

        return $out;
    }

    /** @return list<string> the products an item tags in these readings */
    private static function tagged(array $item, array $readings): array
    {
        $ids = [];
        foreach ($readings as $id => $r) {
            if (self::sourceOf($item, $r) !== null) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /** Where a reading took an item from (its source), null when it does not hold it. */
    private static function sourceOf(array $item, array $reading): ?string
    {
        if (in_array($item['kind'], ['type', 'type_words'], true)) {
            return ($reading['type']['key'] ?? null) === $item['key'] ? (string) $reading['type']['source'] : null;
        }
        $keys = $item['kind'] === 'attribute' ? [$item['key']] : [(string) $item['attribute']];
        foreach ($keys as $attribute) {
            foreach (['category_choices', 'variant_choices', 'text_choices'] as $where) {
                $held = $reading[$where][$attribute] ?? null;
                if ($held === null) {
                    continue;
                }
                $values = is_array($held) ? (array) ($held['values'] ?? [$held['value'] ?? null]) : [(string) $held];
                if ($item['kind'] === 'attribute' || in_array($item['key'], $values, true)) {
                    return match ($where) {
                        'category_choices' => 'category',
                        'variant_choices' => 'variant',
                        default => (string) ($held['source'] ?? 'text'),
                    };
                }
            }
        }

        return null;
    }

    /**
     * The products whose type or value of the item's attribute was there before and is gone or another after: what a
     * product had stays as it was (the bank is completed, never rewritten). A value found in the title that hid the value
     * the picture gave is such a change ("cropped fit" over "relaxed fit": Me369, 18.11).
     */
    private static function lost(array $item, array $before, array $after): int
    {
        $lost = 0;
        $valuesOf = function (array $r, string $a): ?array {
            foreach (['category_choices', 'variant_choices', 'text_choices'] as $where) {
                $held = $r[$where][$a] ?? null;
                if ($held !== null) {
                    $values = is_array($held) ? array_map('strval', (array) ($held['values'] ?? [$held['value'] ?? ''])) : [(string) $held];
                    sort($values);

                    return $values;
                }
            }

            return null;
        };
        foreach ($before as $id => $r) {
            if (in_array($item['kind'], ['type', 'type_words'], true)) {
                $was = $r['type']['key'] ?? null;
                $lost += $was !== null && ($after[$id]['type']['key'] ?? null) !== $was ? 1 : 0;
            } elseif ($item['kind'] !== 'attribute') {
                $a = (string) $item['attribute'];
                $had = $valuesOf($r, $a);
                $lost += $had !== null && $valuesOf($after[$id] ?? [], $a) !== $had ? 1 : 0;
            }
        }

        return $lost;
    }

    /**
     * An existing attribute a new one repeats: they share a word of their keys ("neckline_style" and "neckline") and most
     * of the products the new one tags already have a value of the old. Its values belong there, never beside it.
     */
    private static function repeats(array $item, VocabularyDefinition $bank, array $tagged, array $before): ?string
    {
        $words = array_filter(explode('_', (string) $item['key']), fn (string $w): bool => mb_strlen($w) >= 4);
        foreach ($bank->attributes() as $attribute) {
            $key = (string) $attribute['key'];
            if (array_intersect($words, array_filter(explode('_', $key), fn (string $w): bool => mb_strlen($w) >= 4)) === [] || $tagged === []) {
                continue;
            }
            $has = count(array_filter($tagged, fn (string $id): bool => isset($before[$id]['text_choices'][$key]) || isset($before[$id]['category_choices'][$key]) || isset($before[$id]['variant_choices'][$key])));
            if ($has / count($tagged) >= 0.5) {
                return $key;
            }
        }

        return null;
    }

    /** The value of the same attribute whose products are almost all the new value's and the other way round, if any. */
    private static function twin(array $item, array $after): ?string
    {
        $mine = self::tagged($item, $after);
        if ($mine === []) {
            return null;
        }
        $others = [];
        foreach ($after as $id => $r) {
            foreach ((array) ($r['text_choices'][$item['attribute']]['values'] ?? []) as $v) {
                if ($v !== $item['key']) {
                    $others[$v][] = (string) $id;
                }
            }
        }
        foreach ($others as $value => $ids) {
            $both = count(array_intersect($mine, $ids));
            $either = count(array_unique([...$mine, ...$ids]));
            if ($either > 0 && $both / $either >= self::SAME_VALUE) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function joinValue(array $data, array $item, string $twin): array
    {
        $at = array_search($item['attribute'], array_column($data['attributes'], 'key'), true);
        $values = $data['attributes'][$at]['values'];
        $mine = array_search($item['key'], array_column($values, 'key'), true);
        $into = array_search($twin, array_column($values, 'key'), true);
        $values[$into]['patterns'] = array_values(array_unique([...(array) $values[$into]['patterns'], ...(array) $values[$mine]['patterns']]));
        unset($values[$mine]);
        $data['attributes'][$at]['values'] = array_values($values);

        return $data;
    }

    private static function withoutItem(array $data, array $item): array
    {
        if ($item['kind'] === 'type') {
            $data['product_types'] = array_values(array_filter($data['product_types'], fn (array $t): bool => $t['key'] !== $item['key']));
        } elseif ($item['kind'] === 'value') {
            foreach ($data['attributes'] as $i => $a) {
                if ($a['key'] === $item['attribute'] && count($a['values']) > 2) {
                    $data['attributes'][$i]['values'] = array_values(array_filter($a['values'], fn (array $v): bool => $v['key'] !== $item['key']));
                }
            }
        } elseif ($item['kind'] === 'attribute') {
            $data['attributes'] = array_values(array_filter($data['attributes'], fn (array $a): bool => $a['key'] !== $item['key']));
        }

        return $data;
    }

    /** What a product says of itself, for the auditor: title, the store's fields, the start of its description, its picture. */
    private static function described(CatalogProduct $product, ?array $picture): array
    {
        $fields = [trim((string) ($product->payload['product_type'] ?? ''))];
        foreach (array_filter($product->categoryPaths()) as $path) {
            $fields[] = (string) end($path);
        }
        array_push($fields, ...array_map('strval', (array) ($product->payload['tags'] ?? [])));
        foreach ($product->storeAttributes() as $a) {
            $fields[] = $a['name'].': '.implode(', ', (array) $a['values']);
        }

        return [
            'title' => (string) $product->title,
            'fields' => mb_substr(implode(' · ', array_filter($fields)), 0, 400),
            'description' => mb_substr(TextNormalizer::clean(strip_tags($product->description())), 0, 400),
            'picture' => $picture === null ? '' : mb_substr(PictureText::text($picture), 0, 500),
        ];
    }

    private static function tagOf(array $item, array $reading): array
    {
        if ($item['kind'] === 'type') {
            return ['attribute' => 'product type', 'value' => $item['key']];
        }
        if ($item['kind'] === 'attribute') {
            $held = $reading['text_choices'][$item['key']] ?? $reading['variant_choices'][$item['key']] ?? $reading['category_choices'][$item['key']] ?? [];

            return ['attribute' => $item['key'], 'value' => implode(', ', is_array($held) ? (array) ($held['values'] ?? []) : [(string) $held])];
        }

        return ['attribute' => (string) $item['attribute'], 'value' => (string) $item['key']];
    }

    /**
     * How every product's reading changed: products with a type now that had none, and by attribute the products with a
     * value now that had none, or another value than before.
     *
     * @return array{products: int, typed: int, by_attribute: array<string, array{gained: int, changed: int}>}
     */
    public static function changes(array $before, array $after): array
    {
        $changed = [];
        $typed = 0;
        $by = [];
        $valuesOf = function (array $r): array {
            $out = [];
            foreach (['category_choices', 'variant_choices', 'text_choices'] as $where) {
                foreach ((array) ($r[$where] ?? []) as $attribute => $held) {
                    $out[$attribute] ??= is_array($held) ? array_map('strval', (array) ($held['values'] ?? [$held['value'] ?? ''])) : [(string) $held];
                }
            }

            return $out;
        };
        foreach ($after as $id => $r) {
            $was = $before[$id] ?? [];
            if (($was['type']['key'] ?? null) === null && ($r['type']['key'] ?? null) !== null) {
                $typed++;
                $changed[$id] = true;
            }
            $old = $valuesOf($was);
            foreach ($valuesOf($r) as $attribute => $values) {
                if (! isset($old[$attribute])) {
                    $by[$attribute]['gained'] = ($by[$attribute]['gained'] ?? 0) + 1;
                    $changed[$id] = true;
                } elseif ($old[$attribute] !== $values) {
                    $by[$attribute]['changed'] = ($by[$attribute]['changed'] ?? 0) + 1;
                    $changed[$id] = true;
                }
            }
        }
        foreach ($by as $a => $c) {
            $by[$a] = ['gained' => (int) ($c['gained'] ?? 0), 'changed' => (int) ($c['changed'] ?? 0)];
        }
        ksort($by);

        return ['products' => count($changed), 'typed' => $typed, 'by_attribute' => $by];
    }
}
