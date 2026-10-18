<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Enums\ColorFamily;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Tenancy\Models\Shop;

/**
 * What a shop's search box searches, as one list: every product the store still publishes,
 * every guide and page it shares, and every category with products.
 *
 * Built in code, no model. Each record carries its title and one string of extra words: the
 * categories, tags, brand, SKU and attribute values of a product, the terms of a post, the path
 * of a category, and the shop's synonyms. A synonym "קרש → עץ" adds קרש to every record whose
 * words include עץ, so the search finds them by either word without a rule at query time.
 *
 * Price and stock are not trusted from here: the storefront shows live values. `s` is only a
 * hint for ordering what was in stock last night ahead of what was not.
 */
final class BuildSearchIndex
{
    public const AGENT = 'search.indexer';

    public const ACTION = 'search.build_index';

    public const VERSION = 1;

    /** The filters read from products' variants (variantFacets): the drawer asks them of one variant together. */
    public const VARIANT_COLOUR = 'variant_color';

    public const VARIANT_SIZE = 'variant_size';

    /** A vocabulary's own colour attribute, left out where the variants say the colours. */
    private const COLOUR_KEYS = ['color', 'colour', 'colors', 'colours'];

    /** Letter sizes in their order, as VariantRows keys them. */
    private const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '4XL', '5XL', 'ONE'];

    private const MAX_KEYWORDS = 400;

    /** The phrases that are actions, the same in every shop (HebrewSearch::actions). */
    public const ACTIONS = __DIR__.'/../resources/query-actions.json';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->build($run, $shopId)),
        );
    }

    /** The product's second picture, for the shops that show it on hover; null when it has one. */
    private function secondPicture(CatalogProduct $product): ?string
    {
        foreach (array_slice((array) ($product->payload['images'] ?? []), 1) as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;

            if (is_string($url) && $url !== '' && $url !== $product->image_url) {
                return $url;
            }
        }

        return null;
    }

    private function build(RunContext $run, string $shopId): void
    {
        $own = $this->synonyms();
        // The shop's own, then the word families every shop shares; only its own are counted.
        $synonyms = [...$own, ...self::families()];
        $items = [];
        $counts = ['product' => 0, 'content' => 0, 'category' => 0, 'synonyms' => count($own)];

        // What each product's picture shows, in words, joins its words: found by what it looks like.
        $seen = RetrievalImage::query()->whereNotNull('caption_words')->pluck('caption_words', 'product_id')->map(fn ($w): array => is_array($w) ? $w : (array) json_decode((string) $w, true));

        // What each product is, from the shop's approved facts: the filters beside the results. Then the
        // colours and sizes its variants come in, two filters of their own.
        $facets = $this->facets($shopId);
        $variants = $this->variantFacets($facets['facets'], $this->locale($shopId));
        $faceted = 0;

        foreach (CatalogProduct::query()->active()->with('categories:id,name')->orderBy('id')->lazy(200) as $product) {
            $read = $facets['products'][$product->id] ?? [];
            if (isset($variants[$product->id])) {
                $codes = array_values(array_unique([...($read['f'] ?? []), ...$variants[$product->id]['f']]));
                sort($codes);
                $read = ['f' => $codes, 'v' => $variants[$product->id]['v']] + $read;
            }
            $faceted += isset($read['f']) ? 1 : 0;
            $items[] = $this->record('p:'.$product->external_id, 'product', $product->title, [...$this->productWords($product), ...array_map('strval', (array) ($seen[$product->id] ?? []))], $synonyms, [
                'url' => $product->url,
                'img' => $product->image_url,
                'i2' => $this->secondPicture($product),
                's' => $product->in_stock ? 1 : 0,
                // The price, for "עד 2500 ש״ח": filtering only, never part of a product's words.
                'pr' => $product->price === null ? null : (int) round((float) $product->price),
                'buy' => $product->type === 'simple' && $product->purchasable ? 1 : 0,
                // The code as the store writes it: a search for it shows this product alone.
                'sku' => $product->sku === null ? null : mb_substr(trim((string) $product->sku), 0, 64),
                // For the actions and filters beside the results: on sale, its facets, its size, when it was added.
                'sl' => $product->on_sale ? 1 : null,
                'f' => $read['f'] ?? null,
                // Its variants, each as the colour and size codes it has and whether it is in stock: "black,
                // M, in stock" is asked of one variant, not of a product with black somewhere and M somewhere.
                'v' => $read['v'] ?? null,
                'z' => $read['z'] ?? null,
                'd' => self::added($product),
            ]);
            $counts['product']++;
        }

        // What the store says about itself is for the assistant, never a result of its own.
        foreach (CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->orderBy('id')->lazy(200) as $content) {
            $words = [];

            foreach ((array) $content->terms as $terms) {
                array_push($words, ...array_map('strval', (array) $terms));
            }

            $items[] = $this->record('c:'.$content->external_id, 'content', $content->title, $words, $synonyms, [
                'url' => $content->url,
                'img' => $content->image_url,
                'kind' => $content->type,
            ]);
            $counts['content']++;
        }

        foreach (CatalogCategory::query()->active()->where('product_count', '>', 0)->orderBy('id')->get() as $category) {
            $items[] = $this->record('k:'.$category->external_id, 'category', $category->name, array_map('strval', (array) $category->path), $synonyms, [
                'url' => $category->url,
                'img' => $category->image_url,
                'n' => $category->product_count,
            ]);
            $counts['category']++;
        }

        $counts['answer'] = 0;

        foreach ($this->answers($shopId) as $answer) {
            // w: about the store, not one product or guide; the drawer offers those first, answered as they are
            $items[] = $this->record('a:'.$answer['id'], 'answer', $answer['question'], [], $synonyms, ['ans' => $answer['answer'], 'src' => $answer['sources'], 'w' => $answer['store'] ? 1 : null]);
            $counts['answer']++;
        }

        $json = (string) json_encode(['v' => self::VERSION, 'items' => $items, 'facets' => $facets['facets'], 'size' => $facets['size'], 'actions' => self::actions($this->locale($shopId), $facets['size'] !== null)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = substr(hash('sha256', $json), 0, 20);

        SearchIndex::query()->updateOrCreate(['shop_id' => $shopId], [
            'hash' => $hash,
            'items' => $json,
            'counts' => $counts,
            'built_at' => now(),
        ]);

        $run->output(['counts' => $counts, 'hash' => $hash, 'bytes' => strlen($json), 'faceted_products' => $faceted, 'facets' => count($facets['facets'])])
            ->summary('search::runs.indexed', [
                'products' => (string) $counts['product'],
                'content' => (string) $counts['content'],
                'categories' => (string) $counts['category'],
            ]);
    }

    /**
     * The shop's filters from its active vocabularies (each product type and each choice or
     * yes-or-no attribute, with its values and their names in the shop's language), and for each
     * product the ones its approved facts give, as numbers into that list (facet * 1000 + value),
     * with its size: the approved number of the attribute the shop measures size by
     * (search.size_attribute), when it has one.
     *
     * @return array{facets: list<array{k: string, l: string, v: list<array{k: string, l: string}>}>, size: array{k: string, l: string}|null, products: array<string, array{f?: list<int>, z?: float}>}
     */
    private function facets(string $shopId): array
    {
        $locale = $this->locale($shopId);
        $facets = [];
        $where = [];
        $sizeKey = trim((string) Settings::get('search.size_attribute', $shopId));
        $size = null;
        // Where the variants say the colours (variantFacets), the vocabulary's own colour is not a second colour filter.
        $variantColours = CatalogVariant::query()->whereNotNull('color_families')->exists();

        foreach (EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get() as $vocabulary) {
            $definition = $vocabulary->definition();
            $lists = [['type', 'type', array_map(fn (array $t): string => (string) $t['key'], $definition->productTypes())]];
            foreach ($definition->attributes() as $attribute) {
                $kind = $attribute['type'] ?? 'number';
                if ($variantColours && in_array((string) $attribute['key'], self::COLOUR_KEYS, true)) {
                    continue;
                }
                if ($kind === 'enum') {
                    $lists[] = [(string) $attribute['key'], 'attribute', array_map(fn (array $v): string => (string) $v['key'], (array) $attribute['values'])];
                } elseif ($kind === 'boolean') {
                    $lists[] = [(string) $attribute['key'], 'flag', ['true']];
                } elseif ($attribute['key'] === $sizeKey) {
                    $size ??= ['k' => $sizeKey, 'l' => $definition->label('attribute', $sizeKey, $locale)];
                }
            }
            foreach ($lists as [$key, $kind, $values]) {
                if (! isset($where[$key])) {
                    $where[$key] = ['at' => count($facets), 'values' => []];
                    $facets[] = ['k' => $key, 'l' => $key === 'type' ? (string) __('search::storefront.facet_type', [], $locale) : $definition->label('attribute', $key, $locale), 'v' => []];
                }
                $at = $where[$key]['at'];
                foreach ($values as $value) {
                    if (isset($where[$key]['values'][$value])) {
                        continue;
                    }
                    $where[$key]['values'][$value] = count($facets[$at]['v']);
                    $facets[$at]['v'][] = ['k' => $value, 'l' => match ($kind) {
                        'type' => $definition->label('type', $value, $locale),
                        'flag' => $facets[$at]['l'],
                        default => $definition->label('attribute', $key, $locale, $value),
                    }];
                }
            }
        }

        $products = [];
        if ($facets !== [] || $size !== null) {
            EnrichmentFact::query()->where('status', FactStatus::Approved)->whereNotNull('product_id')
                ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Flag, FactKind::Spec])
                ->select(['id', 'product_id', 'kind', 'key', 'value_text', 'value_number'])
                ->lazyById(2000)->each(function (EnrichmentFact $fact) use (&$products, $where, $sizeKey): void {
                    if ($fact->kind === FactKind::Spec) {
                        if ($fact->key === $sizeKey && $fact->value_number !== null) {
                            $products[$fact->product_id]['z'] = round((float) $fact->value_number, 6);
                        }

                        return;
                    }
                    $key = $fact->kind === FactKind::Type ? 'type' : $fact->key;
                    $value = $fact->kind === FactKind::Flag ? 'true' : (string) $fact->value_text;
                    if (isset($where[$key]['values'][$value])) {
                        $products[$fact->product_id]['f'][] = $where[$key]['at'] * 1000 + $where[$key]['values'][$value];
                    }
                });
            foreach ($products as &$read) {
                if (isset($read['f'])) {
                    $read['f'] = array_values(array_unique($read['f']));
                    sort($read['f']);
                }
            }
            unset($read);
        }

        return ['facets' => $facets, 'size' => $size, 'products' => $products];
    }

    /**
     * Two filters from the products' variants (catalog_variants, read in code): the colour families they
     * come in ("כחול" holds navy and light blue) and their sizes, in the order sizes go. Appended to the
     * shop's filters, and for each product the codes its variants have (f, for the chips and counts) and
     * each distinct variant as [its codes, in stock] (v), so the drawer asks colour, size and stock of
     * one variant together. Nothing when no product has a colour or a size in its variants.
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string, h?: string}>}>  $facets  appended to
     * @return array<string, array{f: list<int>, v: list<array{0: list<int>, 1: int}>}> by product id
     */
    private function variantFacets(array &$facets, string $locale): array
    {
        $families = [];
        $sizes = [];
        $rows = [];

        CatalogVariant::query()->whereHas('product', fn ($q) => $q->whereNull('removed_at'))
            ->select(['id', 'product_id', 'color_families', 'size_key', 'size_label', 'in_stock'])
            ->lazyById(2000)->each(function (CatalogVariant $variant) use (&$families, &$sizes, &$rows): void {
                foreach ((array) $variant->color_families as $family) {
                    $families[(string) $family] = true;
                }
                if ($variant->size_key !== null) {
                    $sizes[$variant->size_key] ??= (string) $variant->size_label;
                }
                $rows[] = [$variant->product_id, (array) $variant->color_families, $variant->size_key, $variant->in_stock ? 1 : 0];
            });

        if ($families === [] && $sizes === []) {
            return [];
        }

        $code = [];
        if ($families !== []) {
            $at = count($facets);
            $values = [];
            foreach (ColorFamily::cases() as $family) {
                if (isset($families[$family->value])) {
                    $code['c:'.$family->value] = $at * 1000 + count($values);
                    $values[] = array_filter(['k' => $family->value, 'l' => (string) __('catalog::colors.families.'.$family->value, [], $locale), 'h' => $family->hex()]);
                }
            }
            $facets[] = ['k' => self::VARIANT_COLOUR, 'l' => (string) __('search::storefront.facet_colour', [], $locale), 'v' => $values];
        }
        if ($sizes !== []) {
            $at = count($facets);
            $values = [];
            foreach (self::sizeOrder(array_keys($sizes)) as $key) {
                $code['z:'.$key] = $at * 1000 + count($values);
                $values[] = ['k' => $key, 'l' => self::sizeLabel($key, $sizes[$key])];
            }
            $facets[] = ['k' => self::VARIANT_SIZE, 'l' => (string) __('search::storefront.facet_size', [], $locale), 'v' => $values];
        }

        $products = [];
        foreach ($rows as [$productId, $colours, $sizeKey, $inStock]) {
            $codes = [...array_map(fn (string $f): ?int => $code['c:'.$f] ?? null, array_map('strval', $colours)), $sizeKey === null ? null : ($code['z:'.$sizeKey] ?? null)];
            $codes = array_values(array_unique(array_filter($codes, fn (?int $c): bool => $c !== null)));
            sort($codes);
            $products[$productId]['f'] = [...($products[$productId]['f'] ?? []), ...$codes];
            // The same colour and size twice (two patterns of one shirt) is one entry, in stock if either is.
            $key = implode(',', $codes);
            $products[$productId]['v'][$key] = [$codes, max($inStock, $products[$productId]['v'][$key][1] ?? 0)];
        }

        foreach ($products as &$product) {
            $product['f'] = array_values(array_unique($product['f']));
            sort($product['f']);
            $product['v'] = array_values($product['v']);
        }
        unset($product);

        // Variants that differ only in what is neither a colour nor a size (a length): nothing to ask of them.
        return array_filter($products, fn (array $product): bool => $product['f'] !== []);
    }

    /**
     * Sizes in the order they go: letters (XS before M before XXL), one size, then numbers, then the rest.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function sizeOrder(array $keys): array
    {
        $rank = function (string $key): array {
            $letter = array_search($key, self::LETTER_SIZES, true);

            return match (true) {
                $letter !== false => [0, (float) $letter, $key],
                is_numeric($key) => [1, (float) $key, $key],
                default => [2, 0.0, $key],
            };
        };
        usort($keys, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return $keys;
    }

    /** A size as a shopper reads it: letter sizes by their key ("XXL" for "2XL"), others as the store wrote them. */
    private static function sizeLabel(string $key, string $label): string
    {
        return in_array($key, self::LETTER_SIZES, true) && $key !== 'ONE' ? $key : ($label !== '' ? $label : $key);
    }

    /**
     * The phrases that are actions, the same in every shop, with their names in the shop's language;
     * those about size only where the shop measures size, so elsewhere "גדול" stays a word.
     *
     * @return list<array{id: string, l: string, patterns: list<string>, do: array<string, string>}>
     */
    private static function actions(string $locale, bool $hasSize): array
    {
        $registry = array_values(array_filter(self::registry(), fn (array $entry): bool => $hasSize || ($entry['needs'] ?? null) !== 'size'));

        return array_map(fn (array $entry): array => [
            'id' => (string) $entry['id'],
            'l' => (string) ($entry['label'][$locale] ?? $entry['label']['he'] ?? $entry['id']),
            'patterns' => array_values((array) $entry['patterns']),
            'do' => (array) $entry['do'],
        ], $registry);
    }

    /**
     * Every phrase that is an action, before a shop's index leaves out what it cannot do.
     *
     * @return list<array<string, mixed>>
     */
    public static function registry(): array
    {
        static $registry = null;

        return $registry ??= array_values((array) (json_decode((string) file_get_contents(self::ACTIONS), true)['actions'] ?? []));
    }

    /** When the store added the product, as a number that sorts (20261012); null when unknown. */
    private static function added(CatalogProduct $product): ?int
    {
        $at = strtotime((string) ($product->payload['created_at'] ?? ''));

        return $at === false || $at <= 0 ? null : (int) date('Ymd', $at);
    }

    private function locale(string $shopId): string
    {
        return (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
    }

    /**
     * Answers shoppers already got and the team did not hide, the most asked first: a question
     * typed in the search box finds its answer before anything is asked. A page's answer points to
     * its page; an answer from the search box to the pages it was written from.
     *
     * @return list<array{id: string, store: bool, question: string, answer: string, sources: list<array{title: string, url: string|null}>}>
     */
    private function answers(string $shopId): array
    {
        $limit = (int) Settings::get('search.answers_in_index', $shopId);

        if ($limit < 1) {
            return [];
        }

        return AssistantAnswer::query()
            ->with(['product:id,title,url,removed_at', 'content:id,title,url'])
            ->where('outcome', AssistantAnswer::ANSWERED)->where('status', AssistantAnswer::SHOWN)->whereNotNull('answer')->shared()
            ->orderByDesc('asked_count')->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (AssistantAnswer $a): bool => $a->product === null || $a->product->removed_at === null)
            ->map(fn (AssistantAnswer $a): array => [
                'id' => $a->id,
                'store' => $a->product_id === null && $a->content_id === null,
                'question' => $a->question,
                'answer' => mb_substr((string) $a->answer, 0, 400),
                'sources' => $a->product !== null
                    ? [['title' => $a->product->title, 'url' => $a->product->url]]
                    : ($a->content !== null ? [['title' => $a->content->title, 'url' => $a->content->url]] : array_map(fn (array $s): array => ['title' => $s['title'], 'url' => $s['url'] ?? null], (array) $a->sources)),
            ])
            ->values()->all();
    }

    private function productWords(CatalogProduct $product): array
    {
        $words = array_filter([(string) $product->brand, (string) $product->sku]);

        foreach ($product->categories as $category) {
            $words[] = (string) $category->name;
        }

        array_push($words, ...array_map('strval', (array) ($product->payload['tags'] ?? [])));
        array_push($words, ...array_map('strval', (array) ($product->payload['brands'] ?? [])));

        foreach ($product->storeAttributes() as $attribute) {
            array_push($words, ...array_map('strval', $attribute['values']));
        }

        return $words;
    }

    /**
     * @param  list<string>  $words
     * @param  list<array{0: string, 1: list<string>}>  $synonyms
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function record(string $id, string $type, string $title, array $words, array $synonyms, array $extra): array
    {
        $keywords = implode(' ', array_unique(array_filter(array_map('trim', $words))));
        $hay = ' '.HebrewSearch::normalize($title.' '.$keywords).' ';
        $added = [];

        foreach ($synonyms as [$term, $means]) {
            $all = true;

            foreach ($means as $word) {
                if (! str_contains($hay, ' '.$word.' ')) {
                    $all = false;
                    break;
                }
            }

            if ($all && ! str_contains($hay, ' '.$term.' ')) {
                $added[$term] = true;
            }
        }

        if ($added !== []) {
            $keywords = trim($keywords.' '.implode(' ', array_keys($added)));
        }

        return array_filter([
            'id' => $id,
            't' => $type,
            'title' => $title,
            'kw' => mb_substr($keywords, 0, self::MAX_KEYWORDS),
        ] + $extra, fn ($value): bool => $value !== null && $value !== '');
    }

    /** @return list<array{0: string, 1: list<string>}> the typed word, and the words it stands for */
    private function synonyms(): array
    {
        $out = [];

        foreach (SearchSynonym::query()->orderBy('term')->get(['term', 'means']) as $synonym) {
            $term = HebrewSearch::normalize($synonym->term);
            $means = array_values(array_filter(explode(' ', HebrewSearch::normalize($synonym->means))));

            if ($term !== '' && $means !== []) {
                $out[] = [$term, $means];
            }
        }

        return $out;
    }

    /**
     * The word families every shop shares (HebrewSearch::FAMILIES), as synonyms: "גינה" is added
     * to whatever says "גינון", so a shopper's garden finds the store's gardening.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function families(): array
    {
        $out = [];

        foreach (HebrewSearch::FAMILIES as $family) {
            $words = array_values(array_unique(array_map(fn (string $w): string => HebrewSearch::normalize($w), $family)));
            foreach ($words as $term) {
                foreach ($words as $other) {
                    if ($other !== $term) {
                        $out[] = [$term, [$other]];
                    }
                }
            }
        }

        return $out;
    }
}
