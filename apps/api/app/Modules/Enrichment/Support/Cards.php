<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Scanning\TextCondenser;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use Illuminate\Support\Collection;

/**
 * The cards every model reads: one product, one page or post, or the store, in one compact JSON.
 * Keys are English and fixed; values are in the shop's own language, already labelled ("מתח": "18 V"),
 * so a model reads it at once, with nothing to look up and nothing to guess. Built in code only, from
 * the catalogue and the approved facts; never a model's unchecked reading.
 *
 * Product: {v, kind, id, title, url, image, type, brand, category, price, regular_price, on_sale,
 *   currency, in_stock, stock, variants: [{id, title, price, in_stock, stock}], facts: {label: value |
 *   [values]}, uses: [], highlights: [], related: {complements|alternatives|family: [{id, title, why}]},
 *   text}
 * Content: {v, kind, id, type, title, url, image, summary, headings: [], text, products: [{id, title}],
 *   promises: {topic: say}}
 * Store: {v, kind, name, url, policies: [{topic, say, quote}], pages: [{title, url}]}
 *
 * Nothing in a card changes with the clock, so its hash changes only when what it says does.
 */
final class Cards
{
    public const VERSION = 1;

    private const MAX_HEADINGS = 12;

    private const MAX_POLICY_QUOTE = 240;

    public function __construct(private readonly TextCondenser $condenser) {}

    /**
     * @param  Collection<int, EnrichmentFact>  $facts  the product's approved facts, with their vocabulary
     * @param  Collection<int, EnrichmentProductRelation>  $relations  its relations, best first
     * @param  array<string, array{id: string, title: string}>  $related  related products by internal id
     * @return array<string, mixed>
     */
    public function product(CatalogProduct $product, Collection $facts, Collection $relations, array $related): array
    {
        $payload = (array) $product->payload;
        $type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type);
        $grouped = [];
        foreach ($facts as $fact) {
            if (! in_array($fact->kind, [FactKind::Spec, FactKind::Choice, FactKind::Flag], true)) {
                continue;
            }
            $label = trim($fact->keyLabel());
            $value = trim($fact->valueLabel());
            if ($label === '' || $value === '') {
                continue;
            }
            $grouped[$label][] = $value;
        }
        $grouped = array_map(fn (array $values): string|array => count($values = array_values(array_unique($values))) === 1 ? $values[0] : $values, $grouped);

        $variants = [];
        foreach (array_slice((array) ($payload['variations'] ?? []), 0, (int) Settings::get('enrichment.card_max_variants')) as $variant) {
            if (! is_array($variant) || ($label = CatalogProduct::variantLabel($variant)) === '') {
                continue;
            }
            $variants[] = self::filled([
                'id' => (string) ($variant['external_id'] ?? ''),
                'title' => $label,
                'price' => is_numeric($variant['price']['price'] ?? null) ? (float) $variant['price']['price'] : null,
                'in_stock' => (bool) ($variant['stock']['in_stock'] ?? false),
                'stock' => self::units((array) ($variant['stock'] ?? [])),
            ]);
        }

        $relatedOut = [];
        $maxRelated = (int) Settings::get('enrichment.card_max_related');
        foreach ($relations as $relation) {
            $other = $related[$relation->related_product_id] ?? null;
            $kind = $relation->kind->value;
            $list = ['complement' => 'complements', 'alternative' => 'alternatives', 'family' => 'family'][$kind] ?? null;
            if ($other === null || $list === null || count($relatedOut[$list] ?? []) >= $maxRelated) {
                continue;
            }
            $relatedOut[$list][] = ['id' => $other['id'], 'title' => $other['title'], 'why' => (string) $relation->source];
        }

        $text = $this->condenser->condense([$product->shortDescription(), $product->description()], (int) Settings::get('enrichment.card_text_chars'), [], true)['text'];

        return self::filled([
            'v' => self::VERSION,
            'kind' => 'product',
            'id' => (string) $product->external_id,
            'title' => (string) $product->title,
            'url' => $product->url,
            'image' => $product->image_url,
            'type' => $type === null ? null : trim($type->valueLabel()),
            'brand' => $product->brand,
            'category' => implode(' > ', $product->categoryPaths()[0] ?? []) ?: null,
            'price' => $product->price === null ? null : (float) $product->price,
            'regular_price' => $product->on_sale && $product->regular_price !== null ? (float) $product->regular_price : null,
            'on_sale' => $product->on_sale ? true : null,
            'currency' => $product->currency ?: null,
            'in_stock' => (bool) $product->in_stock,
            'stock' => self::units((array) ($payload['stock'] ?? [])),
            'variants' => $variants,
            'facts' => $grouped,
            'uses' => array_values(array_unique($facts->filter(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Use)->map(fn (EnrichmentFact $f): string => trim($f->valueLabel()))->filter()->all())),
            'highlights' => array_values(array_unique($facts->filter(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Highlight)->map(fn (EnrichmentFact $f): string => trim((string) $f->value_text))->filter()->all())),
            'related' => $relatedOut,
            'text' => $text,
        ]);
    }

    /**
     * @param  Collection<int, EnrichmentFact>  $promises  the page's approved promises
     * @param  list<array{id: string, title: string}>  $products  the products it names
     * @return array<string, mixed>
     */
    public function content(CatalogContent $content, Collection $promises, array $products): array
    {
        $body = (string) $content->body;
        preg_match_all('~<h[2-4][^>]*>(.*?)</h[2-4]>~isu', $body, $found);
        $headings = array_values(array_unique(array_filter(array_map(fn (string $h): string => trim(TextNormalizer::clean(strip_tags($h))), $found[1] ?? []))));
        $said = [];
        foreach ($promises as $promise) {
            $topic = trim($promise->keyLabel());
            $say = trim($promise->valueLabel());
            if ($topic !== '' && $say !== '') {
                $said[$topic] = $say;
            }
        }

        return self::filled([
            'v' => self::VERSION,
            'kind' => 'content',
            'id' => (string) $content->external_id,
            'type' => $content->type,
            'title' => (string) $content->title,
            'url' => $content->url,
            'image' => $content->image_url,
            'summary' => trim(TextNormalizer::clean(strip_tags((string) $content->excerpt))) ?: null,
            'headings' => array_slice($headings, 0, self::MAX_HEADINGS),
            'text' => $this->condenser->condense([$content->title, $body], (int) Settings::get('enrichment.card_text_chars'), [], true)['text'],
            'products' => $products,
            'promises' => $said,
        ]);
    }

    /**
     * @param  Collection<int, EnrichmentFact>  $policies  the store's approved promises (returns, delivery, warranty)
     * @param  list<array{title: string, url: string}>  $pages  its policy and information pages
     * @return array<string, mixed>
     */
    public function store(string $name, ?string $url, Collection $policies, array $pages): array
    {
        return self::filled([
            'v' => self::VERSION,
            'kind' => 'store',
            'name' => $name,
            'url' => $url,
            'policies' => $policies->map(fn (EnrichmentFact $f): array => self::filled([
                'topic' => trim($f->keyLabel()),
                'say' => trim($f->valueLabel()),
                'quote' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $f->quote)), 0, self::MAX_POLICY_QUOTE),
            ]))->values()->all(),
            'pages' => $pages,
        ]);
    }

    /** The card's hash: what it says, nothing else. @param array<string, mixed> $card */
    public static function hash(array $card): string
    {
        return hash('sha256', (string) json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The units a store counts (its stock managed), null when it does not count them. @param array<string, mixed> $stock */
    private static function units(array $stock): ?int
    {
        return is_numeric($stock['quantity'] ?? null) && ($stock['managed'] ?? true) !== false ? max(0, (int) $stock['quantity']) : null;
    }

    /**
     * Only what is said: an empty field is left out, so a model never reads "null" as a fact.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function filled(array $fields): array
    {
        return array_filter($fields, fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
