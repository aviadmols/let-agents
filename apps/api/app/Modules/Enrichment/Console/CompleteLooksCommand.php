<?php

namespace App\Modules\Enrichment\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * enrichment:complete-looks rocks-and-gold
 *
 * A vocabulary built from a template before its looks were kept (ProposeSchema::unread dropped every
 * value no title in the sample said): Rocks kept two stone colours of thirteen, and a red stone its
 * picture shows had no red to be. The template's values of what a picture may tell (a colour, a stone,
 * a metal, a shape) come back, with the template's bar for pictures, the products are read again
 * (their pictures' descriptions too), and the search index is built again.
 */
final class CompleteLooksCommand extends Command
{
    protected $signature = 'enrichment:complete-looks {shop : shop slug or ID}';

    protected $description = 'Put back the template values a picture may show (colours, stones, metals, shapes) that a vocabulary dropped, and read the products again.';

    public function handle(TenantContext $tenant, ImportVocabulary $import, ReadProductsInCode $read): int
    {
        $shop = Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->first();

        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $vocabularies = $tenant->run($shop->id, fn () => EnrichmentVocabulary::query()->where('active', true)->get());
        $changed = 0;

        foreach ($vocabularies as $vocabulary) {
            $data = (array) $vocabulary->definition;
            $template = ImportVocabulary::template((string) $vocabulary->key);
            if ($template === null) {
                $this->line($vocabulary->key.': no template to complete it from');

                continue;
            }

            [$data, $added] = self::withLooks($data, $template);
            if ($added === []) {
                $this->line($vocabulary->key.': nothing missing');

                continue;
            }

            $import->handle($shop->id, $data, 'complete-looks');
            $changed++;
            $this->line($vocabulary->key.': '.implode(', ', $added));
        }

        if ($changed > 0) {
            $this->line((string) $read->handle($shop->id)->summary());
            Artisan::call('search', ['step' => 'index', 'target' => $shop->slug]);
            $this->line(trim(Artisan::output()));
        }

        return self::SUCCESS;
    }

    /**
     * The vocabulary with the template's values back for each attribute a picture may tell, and the
     * template's bar for pictures on those attributes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $template
     * @return array{0: array<string, mixed>, 1: list<string>} the vocabulary, and "key.value" of each value put back
     */
    public static function withLooks(array $data, array $template): array
    {
        $definition = VocabularyDefinition::fromTrusted($template);
        $fromTemplate = [];
        foreach ((array) ($template['attributes'] ?? []) as $attribute) {
            $fromTemplate[(string) ($attribute['key'] ?? '')] = $attribute;
        }
        $added = [];

        foreach ((array) ($data['attributes'] ?? []) as $i => $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            $source = $fromTemplate[$key] ?? null;
            if ($source === null || ! $definition->readFromImage($key)) {
                continue;
            }
            $have = array_column((array) ($attribute['values'] ?? []), 'key');
            foreach ((array) ($source['values'] ?? []) as $value) {
                if (! in_array($value['key'] ?? null, $have, true)) {
                    $data['attributes'][$i]['values'][] = $value;
                    $added[] = $key.'.'.$value['key'];
                }
            }
            if (isset($template['image']['filter_confidence'][$key])) {
                $data['image']['filter_confidence'][$key] = $template['image']['filter_confidence'][$key];
            }
        }

        return [$data, $added];
    }
}
