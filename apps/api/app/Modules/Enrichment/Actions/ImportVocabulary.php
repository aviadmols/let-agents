<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\Auth;

/**
 * Saves a vocabulary for a shop as a new version of its key; the previous version stops being
 * used for new work. A vocabulary that fails validation is not saved and the run lists why.
 */
final class ImportVocabulary
{
    public const AGENT = 'enrichment.planner';

    public const ACTION = 'enrichment.import_vocabulary';

    /** Ready-made vocabularies any shop can start from. */
    public const TEMPLATES = ['power-tools', 'wood', 'care-and-cleaning', 'fasteners', 'hardware', 'hand-tools', 'adhesives', 'paints', 'jewelry'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public static function template(string $name): ?array
    {
        if (! in_array($name, self::TEMPLATES, true)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents(dirname(__DIR__)."/resources/vocabularies/{$name}.json"), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{run: Run, vocabulary: EnrichmentVocabulary|null}
     */
    public function handle(string $shopId, array $data, ?string $author = null): array
    {
        $vocabulary = null;

        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['key' => $data['key'] ?? null, 'author' => $author],
            work: function (RunContext $run) use ($shopId, $data, $author, &$vocabulary): void {
                [$definition, $problems] = VocabularyDefinition::parse($data);

                if ($definition === null) {
                    $run->output(['problems' => array_slice($problems, 0, 50)])
                        ->fail('enrichment::runs.vocabulary_invalid', ['count' => count($problems)], implode('; ', array_slice($problems, 0, 10)));

                    return;
                }

                $vocabulary = $this->tenant->run($shopId, function () use ($shopId, $definition, $author): EnrichmentVocabulary {
                    $previous = EnrichmentVocabulary::query()->where('key', $definition->key());
                    $version = ((int) (clone $previous)->max('version')) + 1;

                    if ((clone $previous)->where('definition_hash', $definition->hash())->where('active', true)->exists()) {
                        return (clone $previous)->where('definition_hash', $definition->hash())->where('active', true)->firstOrFail();
                    }

                    (clone $previous)->update(['active' => false]);

                    return EnrichmentVocabulary::query()->create([
                        'shop_id' => $shopId,
                        'key' => $definition->key(),
                        'version' => $version,
                        'root_category_external_id' => $definition->rootCategoryExternalId(),
                        'definition' => $definition->toArray(),
                        'definition_hash' => $definition->hash(),
                        'author' => $author === null ? null : mb_substr($author, 0, 120),
                        'active' => true,
                        'created_by' => Auth::id(),
                    ]);
                });

                $run->output(['vocabulary_id' => $vocabulary->id, 'version' => $vocabulary->version])
                    ->summary('enrichment::runs.vocabulary_saved', [
                        'name' => $definition->name(app()->getLocale()),
                        'version' => $vocabulary->version,
                        'types' => count($definition->productTypes()),
                        'attributes' => count($definition->attributes()),
                        'tags' => count($definition->tags()),
                    ]);
            },
        );

        return ['run' => $run, 'vocabulary' => $vocabulary];
    }
}
