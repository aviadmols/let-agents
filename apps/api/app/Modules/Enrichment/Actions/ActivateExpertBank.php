<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * A stored expert bank that passed, made the store's vocabulary by a person, and undone the same way. Nothing here asks a
 * model: the bank was written, measured and checked already (BuildExpertBank).
 *
 * activate(): the bank becomes a vocabulary of the usual shape (types and attributes with their values and the patterns
 * code built from the store's words), installed through the usual path (ImportVocabulary) over the whole catalogue, and
 * every other active vocabulary of the store is retired, as a branch whose vocabulary no longer serves is (BuildStructure):
 * one product is read by one vocabulary. The bank keeps what it replaced. Every choice is read the longest phrase first,
 * as the bank was measured, and from the store's words only, never from a picture: nothing was measured on pictures.
 *
 * deactivate(): the bank's vocabulary is retired and exactly the vocabularies it replaced come back, unless a newer
 * version of one is active by then.
 *
 * Either way, the facts and the filters follow only when code reads the products again (ReadProductsInCode) and the
 * search index is built again: the command prints both; neither runs here.
 */
final class ActivateExpertBank
{
    public const ACTION = 'enrichment.activate_expert_bank';

    public const UNDO = 'enrichment.deactivate_expert_bank';

    /** The vocabulary key an expert bank is installed under: each activation a new version of it. */
    public const KEY = 'expert_bank';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ImportVocabulary $import,
    ) {}

    /** @return array{run: Run, vocabulary: ?EnrichmentVocabulary, replaced: list<array{id: string, key: string, version: int}>} */
    public function activate(string $shopId, string $bankId): array
    {
        $vocabulary = null;
        $replaced = [];
        $run = $this->runs->track(
            agent: BuildExpertBank::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['expert_bank' => $bankId],
            work: function (RunContext $run) use ($shopId, $bankId, &$vocabulary, &$replaced): void {
                $this->tenant->run($shopId, function () use ($run, $shopId, $bankId, &$vocabulary, &$replaced): void {
                    [$vocabulary, $replaced] = $this->install($run, $shopId, $bankId);
                });
            },
        );

        return ['run' => $run, 'vocabulary' => $vocabulary, 'replaced' => $replaced];
    }

    /** @return array{run: Run, bank: ?EnrichmentExpertBank, restored: list<array{id: string, key: string, version: int}>} */
    public function deactivate(string $shopId): array
    {
        $bank = null;
        $restored = [];
        $run = $this->runs->track(
            agent: BuildExpertBank::AGENT,
            action: self::UNDO,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId, &$bank, &$restored): void {
                $this->tenant->run($shopId, function () use ($run, &$bank, &$restored): void {
                    [$bank, $restored] = $this->undo($run);
                });
            },
        );

        return ['run' => $run, 'bank' => $bank, 'restored' => $restored];
    }

    /**
     * The bank as a vocabulary of the usual shape (resources/vocabularies/*.json): over the whole catalogue, each type
     * and value with its label in both languages (the store's own, as the bank has it), the words code read it by and
     * the patterns code built from them; each attribute a choice of the types it belongs to, read the longest phrase
     * first and never from a picture.
     *
     * @return array<string, mixed>
     */
    public static function definition(EnrichmentExpertBank $bank): array
    {
        $label = fn (string $text): array => ['he' => $text, 'en' => $text];
        // a key of one letter is a key of the bank and none of a vocabulary (two letters at least)
        $key = fn (string $key): string => strlen($key) < 2 ? $key.'_' : $key;
        $entry = fn (array $item): array => [
            'key' => $key((string) $item['key']),
            'label' => $label((string) $item['label']),
            'patterns' => array_values(array_map('strval', (array) ($item['patterns'] ?? array_map(ExpertBankShape::pattern(...), (array) $item['words'])))),
            'words' => array_values(array_map('strval', (array) $item['words'])),
        ] + (empty($item['broad']) ? [] : ['broad' => true]);

        $attributes = [];
        foreach ((array) ($bank->bank['attributes'] ?? []) as $attribute) {
            // a vocabulary's attribute is a choice of two values at least: one the gate let pass with a single value (whom
            // it is for: "Men" alone, Naot 19.11) stays out of the vocabulary, never failing the whole bank's activation
            if (count((array) ($attribute['values'] ?? [])) < 2) {
                continue;
            }
            $attributes[] = array_filter([
                'key' => $key((string) $attribute['key']),
                'type' => 'enum',
                'multiple' => ($attribute['multiple'] ?? false) === true,
                'longest_phrase' => true,
                'visible' => false,
                'label' => $label((string) $attribute['label']),
                'applies_to' => array_values(array_map(fn ($type): string => $key((string) $type), (array) ($attribute['types'] ?? []))),
                'values' => array_map($entry, (array) $attribute['values']),
            ], fn ($value): bool => $value !== []);
        }

        return [
            'schema_version' => VocabularyDefinition::SCHEMA_VERSION,
            'key' => self::KEY,
            'name' => [
                'he' => (string) trans('enrichment::runs.expert_bank_vocabulary_name', [], 'he'),
                'en' => (string) trans('enrichment::runs.expert_bank_vocabulary_name', [], 'en'),
            ],
            VocabularyDefinition::EXPERT_BANK => (string) $bank->id,
            'root_category_external_id' => VocabularyDefinition::WHOLE_CATALOG,
            'excluded_category_external_ids' => [],
            'product_types' => array_map($entry, (array) ($bank->bank['types'] ?? [])),
            'attributes' => $attributes,
        ];
    }

    /** @return array{0: ?EnrichmentVocabulary, 1: list<array{id: string, key: string, version: int}>} */
    private function install(RunContext $run, string $shopId, string $bankId): array
    {
        $bank = EnrichmentExpertBank::query()->whereKey($bankId)->first();
        if ($bank === null) {
            $run->fail('enrichment::runs.expert_bank_activate_none', ['id' => $bankId]);

            return [null, []];
        }
        // Only a bank whose own numbers reached every threshold; a held or failed one is a report and stays one.
        if ($bank->status !== EnrichmentExpertBank::PASSED || $bank->bank === null) {
            $run->fail('enrichment::runs.expert_bank_activate_not_passed', ['id' => $bank->id, 'status' => $bank->status]);

            return [null, []];
        }
        if (! Features::enabled(BuildExpertBank::FEATURE, $shopId)) {
            $run->fail('enrichment::runs.expert_bank_off');

            return [null, []];
        }
        $other = EnrichmentExpertBank::query()->where('status', EnrichmentExpertBank::ACTIVATED)->latest()->first();
        if ($other !== null) {
            // one bank at a time: the undo restores what the first one replaced
            $run->fail('enrichment::runs.expert_bank_activate_other', ['id' => $other->id]);

            return [null, []];
        }

        $before = EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get();
        ['run' => $imported, 'vocabulary' => $vocabulary] = $this->import->handle($shopId, self::definition($bank), 'expert_bank:'.$bank->id);
        if ($vocabulary === null) {
            $run->output(['import_run' => $imported->id, 'problems' => $imported->output['problems'] ?? []])
                ->fail('enrichment::runs.expert_bank_activate_invalid', ['id' => $bank->id], (string) $imported->summary());

            return [null, []];
        }

        $replaced = $before->reject(fn (EnrichmentVocabulary $v): bool => $v->id === $vocabulary->id)
            ->map(fn (EnrichmentVocabulary $v): array => ['id' => (string) $v->id, 'key' => (string) $v->key, 'version' => (int) $v->version])->values()->all();

        DB::transaction(function () use ($bank, $vocabulary, $replaced, $run): void {
            // One product, one vocabulary: the bank reads the whole catalogue, so every other one is retired, kept for the undo.
            EnrichmentVocabulary::query()->whereIn('id', array_column($replaced, 'id'))->update(['active' => false]);
            $bank->update([
                'status' => EnrichmentExpertBank::ACTIVATED,
                'activation' => [
                    'vocabulary_id' => (string) $vocabulary->id,
                    'version' => (int) $vocabulary->version,
                    'replaced' => $replaced,
                    'activated_at' => now()->toIso8601String(),
                    'run_id' => $run->runId,
                ],
            ]);
        });

        $definition = $vocabulary->definition();
        $run->output(['expert_bank' => $bank->id, 'vocabulary_id' => $vocabulary->id, 'version' => $vocabulary->version, 'replaced' => $replaced])
            ->summary('enrichment::runs.expert_bank_activated', [
                'id' => $bank->id,
                'version' => $vocabulary->version,
                'types' => count($definition->productTypes()),
                'attributes' => count($definition->attributes()),
                'replaced' => $replaced === [] ? '-' : implode(', ', array_map(fn (array $v): string => $v['key'].' v'.$v['version'], $replaced)),
            ]);

        return [$vocabulary, $replaced];
    }

    /** @return array{0: ?EnrichmentExpertBank, 1: list<array{id: string, key: string, version: int}>} */
    private function undo(RunContext $run): array
    {
        $bank = EnrichmentExpertBank::query()->where('status', EnrichmentExpertBank::ACTIVATED)->latest()->first();
        if ($bank === null) {
            $run->fail('enrichment::runs.expert_bank_deactivate_none');

            return [null, []];
        }

        $restored = [];
        DB::transaction(function () use ($bank, &$restored): void {
            // the bank's vocabulary, in whatever version it has reached since (a completion writes a new one)
            EnrichmentVocabulary::query()->where('key', self::KEY)->where('active', true)->update(['active' => false]);
            foreach ((array) ($bank->activation['replaced'] ?? []) as $was) {
                $vocabulary = EnrichmentVocabulary::query()->whereKey($was['id'])->first();
                // a newer version of the same key active by now stands; the old one does not come back beside it
                if ($vocabulary === null || EnrichmentVocabulary::query()->where('key', $vocabulary->key)->where('active', true)->exists()) {
                    continue;
                }
                $vocabulary->update(['active' => true]);
                $restored[] = ['id' => (string) $vocabulary->id, 'key' => (string) $vocabulary->key, 'version' => (int) $vocabulary->version];
            }
            $bank->update([
                'status' => EnrichmentExpertBank::PASSED,
                'activation' => (array) $bank->activation + ['deactivated_at' => now()->toIso8601String(), 'restored' => array_column($restored, 'id')],
            ]);
        });

        $run->output(['expert_bank' => $bank->id, 'restored' => $restored])
            ->summary('enrichment::runs.expert_bank_deactivated', [
                'id' => $bank->id,
                'restored' => $restored === [] ? '-' : implode(', ', array_map(fn (array $v): string => $v['key'].' v'.$v['version'], $restored)),
            ]);

        return [$bank, $restored];
    }
}
