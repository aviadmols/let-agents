<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Scanning\SchemaStats;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * The first step of a shop's own vocabulary, in code only: a sample of its products, from every
 * kind it sells, counted (see SchemaStats). No model is called. The counts are kept as a proposal
 * waiting to be written; when they are the same as the last proposal's, nothing new is kept.
 */
final class DiscoverSchema
{
    public const AGENT = 'enrichment.schema_discovery';

    public const ACTION = 'enrichment.discover_schema';

    /** The counting's own version, part of the fingerprint: a change here counts again. */
    public const VERSION = 1;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{run: Run, proposal: ?EnrichmentSchemaProposal} the proposal is null when nothing new was found */
    public function handle(string $shopId, ?int $sample = null): array
    {
        $proposal = null;
        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId, $sample, &$proposal): void {
                $proposal = $this->tenant->run($shopId, fn () => $this->discover($run, $shopId, $sample));
            },
        );

        return ['run' => $run, 'proposal' => $proposal];
    }

    private function discover(RunContext $run, string $shopId, ?int $size): ?EnrichmentSchemaProposal
    {
        $sample = $this->sample($size ?? (int) Settings::get('enrichment.schema_sample', $shopId));

        if ($sample === []) {
            $run->output(['sampled' => 0])->summary('enrichment::runs.schema_nothing');

            return null;
        }

        $templates = [];
        foreach (ImportVocabulary::TEMPLATES as $name) {
            $data = ImportVocabulary::template($name);
            if ($data !== null) {
                $templates[$name] = VocabularyDefinition::fromTrusted($data);
            }
        }

        // The sample's ids stay with the counts, so the proposal's patterns are measured on the same products.
        $stats = SchemaStats::collect(CatalogProduct::query()->whereIn('id', $sample)->orderBy('external_id')->get(), $templates) + ['sample' => $sample];
        $fingerprint = hash('sha256', self::VERSION.'|'.json_encode($stats, JSON_UNESCAPED_UNICODE));
        $last = EnrichmentSchemaProposal::query()->latest()->first();
        $template = array_key_first($stats['templates'] ?? []);
        $fit = $template === null ? 0 : $stats['templates'][$template];

        if ($last !== null && $last->fingerprint === $fingerprint && $last->status !== EnrichmentSchemaProposal::FAILED) {
            $run->output(['sampled' => $stats['sampled'], 'unchanged' => $last->id])->summary('enrichment::runs.schema_unchanged');

            return null;
        }

        $proposal = EnrichmentSchemaProposal::query()->create([
            'shop_id' => $shopId,
            'status' => EnrichmentSchemaProposal::DISCOVERED,
            'sampled' => $stats['sampled'],
            // A ready vocabulary is a starting point only when its types fit a good share of the titles.
            'template' => $fit >= ProposeSchema::TEMPLATE_FIT ? $template : null,
            'stats' => $stats,
            'fingerprint' => $fingerprint,
            'run_id' => $run->runId,
        ]);

        $keys = count($stats['attributes']) + count($stats['spec_fields']) + count($stats['measurements']) + count($stats['key_values']);
        $run->output(['sampled' => $stats['sampled'], 'proposal' => $proposal->id, 'keys' => $keys, 'templates' => $stats['templates']])
            ->summary('enrichment::runs.schema_discovered', [
                'products' => (string) $stats['sampled'],
                'keys' => (string) $keys,
                'template' => $template ?? '-',
                'fit' => (string) round($fit * 100),
            ]);

        return $proposal;
    }

    /**
     * Product ids, spread over the kinds the shop sells (its type, or else its first category),
     * the same ones every time for the same catalog: within each kind by a hash of the id.
     *
     * @return list<string>
     */
    private function sample(int $size): array
    {
        $groups = [];

        CatalogProduct::query()->active()->select(['id', 'external_id', 'payload'])->orderBy('id')
            ->chunk(500, function ($products) use (&$groups): void {
                foreach ($products as $product) {
                    $kind = trim((string) ($product->payload['product_type'] ?? ''));
                    if ($kind === '') {
                        $paths = $product->categoryPaths();
                        $kind = (string) ($paths[0][0] ?? '');
                    }
                    $groups[$kind][] = [crc32((string) $product->external_id), (string) $product->id];
                }
            });

        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            sort($group);
        }
        unset($group);

        // One from each kind in turn, until the sample is full.
        $out = [];
        for ($round = 0; count($out) < $size; $round++) {
            $added = false;
            foreach ($groups as $group) {
                if (isset($group[$round])) {
                    $out[] = $group[$round][1];
                    $added = true;
                    if (count($out) >= $size) {
                        break;
                    }
                }
            }
            if (! $added) {
                break;
            }
        }

        return $out;
    }
}
