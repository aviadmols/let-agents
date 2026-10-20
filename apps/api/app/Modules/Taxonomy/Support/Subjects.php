<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Products and posts as a model reads them to tag them, and as code checks what it said: the
 * store's own words (title, categories, brand and tags, fields, what the scan learned and kept,
 * the description) apart from what its picture shows. Each with a hash of all of it, so a product
 * is tagged again only when what it says changed. Code only; call inside the shop's tenant.
 */
final class Subjects
{
    /** The kinds of fact the scan learned that say what a product is or has. */
    private const LEARNED = [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Use, FactKind::Tag, FactKind::Category];

    /**
     * @param  Collection<int, CatalogProduct>  $products
     * @return array<string, array{type: string, id: string, title: string, groups: list<string>, attributes: list<string>, text: string, picture: string, hash: string}> by product id
     */
    public static function products(Collection $products): array
    {
        $products = EloquentCollection::make($products->all())->loadMissing('categories:id,name');
        $ids = $products->pluck('id')->all();
        $chars = (int) Settings::get('taxonomy.text_chars');
        $learned = [];
        EnrichmentFact::query()->whereIn('product_id', $ids)->where('status', FactStatus::Approved)
            ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, self::LEARNED))
            ->orderBy('key')->orderBy('id')->get(['product_id', 'key', 'value_text', 'value_number', 'unit'])
            ->each(function (EnrichmentFact $f) use (&$learned): void {
                $value = $f->value_text ?? ($f->value_number === null ? null : rtrim(rtrim((string) $f->value_number, '0'), '.').($f->unit ? ' '.$f->unit : ''));
                if ($value !== null && $value !== '' && ! in_array($value, ['none', 'unknown'], true)) {
                    $learned[(string) $f->product_id][$f->key][$value] = true;
                }
            });
        $pictures = RetrievalImage::query()->whereIn('product_id', $ids)->whereNotNull('caption')->orderBy('id')->get(['product_id', 'caption', 'caption_words'])
            ->groupBy('product_id')->map(fn (Collection $images): string => mb_substr(trim(implode(' ', array_filter([(string) $images->first()->caption, implode(', ', (array) $images->first()->caption_words)]))), 0, 400));

        $out = [];
        foreach ($products as $product) {
            $tags = array_map(fn ($t): string => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t, (array) ($product->payload['tags'] ?? []));
            $groups = array_values(array_unique(array_filter([...$product->categories->pluck('name')->all(), (string) $product->brand, ...$tags])));
            sort($groups, SORT_STRING);
            $attributes = array_map(fn (array $a): string => $a['name'].': '.implode(', ', array_slice($a['values'], 0, 8)), array_slice($product->storeAttributes(), 0, 10));
            foreach ($learned[$product->id] ?? [] as $key => $values) {
                $attributes[] = $key.': '.implode(', ', array_slice(array_keys($values), 0, 6));
            }
            sort($attributes, SORT_STRING);
            $item = [
                'type' => TaxonomyAssignment::PRODUCT,
                'id' => (string) $product->id,
                'title' => (string) $product->title,
                'groups' => $groups,
                'attributes' => $attributes,
                'text' => mb_substr(self::plain((string) ($product->payload['short_description'] ?? '').' '.(string) ($product->payload['description'] ?? '')), 0, $chars),
                'picture' => (string) ($pictures[$product->id] ?? ''),
            ];
            $out[$product->id] = $item + ['hash' => hash('sha256', (string) json_encode($item, JSON_UNESCAPED_UNICODE))];
        }

        return $out;
    }

    /**
     * @param  Collection<int, CatalogContent>  $posts
     * @return array<string, array{type: string, id: string, title: string, groups: list<string>, attributes: list<string>, text: string, picture: string, hash: string}> by content id
     */
    public static function posts(Collection $posts): array
    {
        $chars = (int) Settings::get('taxonomy.text_chars');
        $out = [];

        foreach ($posts as $post) {
            $groups = array_values(array_unique(array_filter(array_merge(...array_values(array_map(fn ($v): array => array_map('strval', (array) $v), (array) $post->terms))) ?: [])));
            sort($groups, SORT_STRING);
            $item = [
                'type' => TaxonomyAssignment::CONTENT,
                'id' => (string) $post->id,
                'title' => (string) $post->title,
                'groups' => $groups,
                'attributes' => ['kind: '.$post->type],
                'text' => mb_substr(self::plain((string) $post->excerpt.' '.(string) $post->body), 0, $chars),
                'picture' => '',
            ];
            $out[$post->id] = $item + ['hash' => hash('sha256', (string) json_encode($item, JSON_UNESCAPED_UNICODE))];
        }

        return $out;
    }

    /** What the store itself says of a subject, as a quote from it is looked for. */
    public static function catalogText(array $item): string
    {
        return implode(' ', [$item['title'], implode(' ', $item['groups']), implode(' ', $item['attributes']), $item['text']]);
    }

    private static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $html)))));
    }
}
