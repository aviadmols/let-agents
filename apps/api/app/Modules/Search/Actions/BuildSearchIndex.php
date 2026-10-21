<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Enums\ColorFamily;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
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
use App\Modules\Search\Contracts\ProductTags;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\ShownQuestion;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

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

    /** Products with a size on their page before those sizes are a filter. */
    private const MIN_PAGE_SIZED = 3;

    /** A vocabulary's own colour attribute, left out where the variants say the colours. */
    private const COLOUR_KEYS = ['color', 'colour', 'colors', 'colours'];

    /** Letter sizes in their order, as VariantRows keys them. */
    private const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '4XL', '5XL', 'ONE'];

    private const MAX_KEYWORDS = 600;

    /** The categories the drawer offers as a filter, the most used first. */
    private const MAX_CATEGORIES = 12;

    /** A category holding fewer products than this is not worth a filter. */
    private const CATEGORY_MIN_PRODUCTS = 3;

    /** A category holding this share of the products or more narrows nothing. */
    private const CATEGORY_MAX_SHARE = 0.9;

    /** A store field's value longer than this is a sentence, not a filter value. */
    private const FIELD_VALUE_CHARS = 60;

    /** A field whose values are this nearly all different (a code, a date) narrows nothing. */
    private const FIELD_MAX_DISTINCT = 0.9;

    /** @var array<string, list<string>> the store-field values each product has (fieldFacets), words of it */
    private array $fieldValues = [];

    /** Words that say a collection is a campaign or a shelf of the moment, not a kind of product. */
    private const CAMPAIGN_WORDS = '~sale|friday|cyber|valentine|summer|winter|edit|black|gift ?guide|new ?arrivals?|best ?sellers?|trending|shop ?all|מבצע|סייל|סוף[- ]?שנה|חג|הגיעו לאחרונה|חדש(ים|ות)? באתר|המלצות|עולות|הכי נמכר|רבי מכר|קולקציית|מתנות ל~iu';

    /** Whether a category's name is a kind a shopper narrows by: not an address, not a campaign. */
    public static function shopperCategory(string $name): bool
    {
        $name = trim($name);

        // "the-summer-edit", "shoppingil", "rings, made-by-rocks-and-gold": the store's address of a collection,
        // lowercase Latin with hyphens or one lowercase word; "עולות-עכשיו", "טו-באב": one word joined by hyphens.
        $address = (preg_match('/^[a-z0-9&,\s_-]+$/u', $name) === 1 && (str_contains($name, '-') || preg_match('/^[a-z0-9_]+$/u', $name) === 1))
            || preg_match('/^\S+[-_]\S+$/u', $name) === 1;

        return ! $address && preg_match(self::CAMPAIGN_WORDS, $name) !== 1;
    }

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

    /**
     * A size kept in the product's own fields (a Shopify metafield "custom.ring_size": "54"): a field
     * named for a size whose value is one number a size can be. Null when none.
     */
    private static function metaSize(CatalogProduct $product): ?float
    {
        foreach ((array) ($product->payload['meta'] ?? []) as $key => $value) {
            if (is_string($key) && preg_match('/(^|[._-])(ring_?size|size|current_size|מידה)$/iu', $key) === 1 && is_scalar($value)
                && preg_match('/^\s*(\d{1,3}(?:[.,]\d{1,2})?)\s*$/u', (string) $value, $m) === 1 && (float) str_replace(',', '.', $m[1]) > 0 && (float) str_replace(',', '.', $m[1]) <= 120) {
                return round((float) str_replace(',', '.', $m[1]), 6);
            }
        }

        return null;
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
        // A product's own size where the shop has no size attribute: what its page says (a ring's "מידה
        // נוכחית: 54", ReadPageChoices), else a size-like custom field of its feed. Sizes from facts come first.
        $pageSizes = CatalogProductChoice::query()->whereNotNull('size')->pluck('size', 'product_id')->map(fn ($z): float => round((float) $z, 6));
        // A product whose page asks a choice before the cart (a length, an add-on: ReadPageChoices) opens its choices
        // in the drawer, not an add the store refuses. Counted here, not in SQL: a json column.
        $asks = CatalogProductChoice::query()->get(['product_id', 'fields'])
            ->filter(fn (CatalogProductChoice $c): bool => collect((array) $c->fields)->contains(fn ($field): bool => is_array($field) && ! empty($field['required'])))
            ->pluck('product_id')->flip();
        $sized = false;
        $variants = $this->variantFacets($facets['facets'], $this->locale($shopId));
        // Where no variant has a size, the sizes the product pages say are the size filter (a ring of one size each).
        // Every size the page offers it in, a resize away ("הגדלה ל 54"), is one of its sizes too: "מידה 54" finds the ring of 53.
        $offered = CatalogProductChoice::query()->whereNotNull('sizes')->pluck('sizes', 'product_id');
        $paged = $this->pageSizeFacet($facets['facets'], $pageSizes->map(fn (float $z, string $id): array => array_values(array_unique([$z, ...array_map('floatval', (array) ($offered[$id] ?? []))])))->all(), $this->locale($shopId));
        // The store's own categories, a filter of their own: the shopper narrows by them.
        $categories = $this->categoryFacet($facets['facets'], $this->locale($shopId));
        // The tags sure enough to narrow by, where the shop has them as filters: a group each ("ז'אנר").
        $tagFilters = $this->tagFacets($facets['facets'], $shopId, $this->locale($shopId));
        // The store's own fields most of its products have, each a filter of its own: a bookshop's "מחבר".
        // Their values are words of the products either way: "הספר של עומר שם טוב" finds his book, whose title does not say him.
        $apart = $facets['facets'];
        $fields = Features::enabled('search.field_filters', $shopId) ? $this->fieldFacets($facets['facets'], $shopId, $facets['products']) : [];
        if (! Features::enabled('search.field_filters', $shopId)) {
            $this->fieldFacets($apart, $shopId, $facets['products']);
        }
        // What the scan learned each product is and is for, in words: found by them, before the long lists.
        $learned = $this->learnedWords($this->locale($shopId));
        // What each product is about by meaning, from its tags, where the shop has tags in search: found by
        // them as by its own words, never instead of them ("ספרי חטופים" finds a book whose text says "נחטף")
        $tagged = app()->bound(ProductTags::class) ? app(ProductTags::class)->tagsByProduct($shopId) : [];
        $faceted = 0;

        foreach (CatalogProduct::query()->active()->with('categories:id,name')->orderBy('id')->lazy(200) as $product) {
            $read = $facets['products'][$product->id] ?? [];
            // The names of what the store's approved facts say it is ("אמרלד קאט", "קרנליאן", "ספר מודפס") are words it
            // is found by, whatever its own text calls them ("קרנליון"): a filter's name finds what the filter holds.
            $named = array_map(fn (int $code): string => (string) ($facets['facets'][intdiv($code, 1000)]['v'][$code % 1000]['l'] ?? ''), $read['f'] ?? []);
            $codes = [...($read['f'] ?? []), ...($variants[$product->id]['f'] ?? []), ...($paged[$product->id] ?? []), ...($categories[$product->id] ?? []), ...($tagFilters[$product->id] ?? []), ...($fields[$product->id] ?? [])];
            if ($codes !== []) {
                $codes = array_values(array_unique($codes));
                sort($codes);
                $read = ['f' => $codes] + $read;
            }
            if (isset($variants[$product->id])) {
                $read['v'] = $variants[$product->id]['v'];
            }
            $faceted += isset($read['f']) ? 1 : 0;
            $items[] = $this->record('p:'.$product->external_id, 'product', $product->title, [...$this->productWords($product, $learned[$product->id] ?? []), ...$named, ...($this->fieldValues[(string) $product->id] ?? []), ...array_map('strval', (array) ($seen[$product->id] ?? []))], $synonyms, [
                'url' => $product->url,
                'img' => $product->image_url,
                // its tags by meaning, apart from its words: weighed by how central each is ([[words, weight], …])
                'tg' => $tagged[$product->id] ?? null,
                'i2' => $this->secondPicture($product),
                's' => $product->in_stock ? 1 : 0,
                // The price, for "עד 2500 ש״ח": filtering only, never part of a product's words.
                'pr' => $product->price === null ? null : (int) round((float) $product->price),
                'buy' => $product->type === 'simple' && $product->purchasable && ! isset($asks[$product->id]) ? 1 : 0,
                // The code as the store writes it: a search for it shows this product alone.
                'sku' => $product->sku === null ? null : mb_substr(trim((string) $product->sku), 0, 64),
                // For the actions and filters beside the results: on sale, its facets, its size, when it was added.
                'sl' => $product->on_sale ? 1 : null,
                'f' => $read['f'] ?? null,
                // Its variants, each as the colour and size codes it has and whether it is in stock: "black,
                // M, in stock" is asked of one variant, not of a product with black somewhere and M somewhere.
                'v' => $read['v'] ?? null,
                'z' => $z = $read['z'] ?? $pageSizes[$product->id] ?? self::metaSize($product),
                'd' => self::added($product),
            ]);
            $counts['product']++;
            $sized = $sized || $z !== null;
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
            // w: about the store, not one product or guide; ak: what it is about (product, content, store), and
            // an: how many asked it: the drawer's "what people ask here" shows the most asked, then one of each kind
            $items[] = $this->record('a:'.$answer['id'], 'answer', $answer['question'], [], $synonyms, ['ans' => $answer['answer'], 'src' => $answer['sources'], 'w' => $answer['store'] ? 1 : null,
                'ak' => $answer['kind'], 'an' => $answer['asked']]);
            $counts['answer']++;
        }

        $json = (string) json_encode(['v' => self::VERSION, 'items' => $items, 'facets' => $facets['facets'], 'size' => $facets['size'], 'actions' => self::actions($this->locale($shopId), $facets['size'] !== null || $sized, in_array(self::VARIANT_SIZE, array_column($facets['facets'], 'k'), true))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = substr(hash('sha256', $json), 0, 20);

        SearchIndex::query()->updateOrCreate(['shop_id' => $shopId], [
            'hash' => $hash,
            'items' => $json,
            'counts' => $counts,
            'built_at' => now(),
        ]);

        $run->output(['counts' => $counts, 'hash' => $hash, 'bytes' => strlen($json), 'faceted_products' => $faceted, 'facets' => count($facets['facets']),
            // what the shopper filters by, each with its values: what this build made, for the run's page
            'filters' => collect($facets['facets'])->filter(fn (array $f): bool => ($f['v'] ?? []) !== [])->mapWithKeys(fn (array $f): array => [(string) $f['l'] => count($f['v']).': '.implode(', ', array_slice(array_column($f['v'], 'l'), 0, 12))])->all()])
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
     * The size filter of a shop whose products each come in one size, said on their pages ("מידה נוכחית:
     * 54", ReadPageChoices) and not in their variants: the sizes there are, in number order, each product
     * under its own. Nothing where the variants already make a size filter, or fewer than two sizes or
     * MIN_PAGE_SIZED products have one.
     *
     * @param  list<array{k: string, l: string, v: list<array<string, string>>}>  $facets  appended to
     * @param  array<string, list<float>>  $sizes  every size of each product, by product id
     * @return array<string, list<int>> the product's code, by product id
     */
    private function pageSizeFacet(array &$facets, array $sizes, string $locale): array
    {
        $values = array_values(array_unique(array_map(fn (float $z): string => self::sizeText($z), array_merge([], ...array_values($sizes)))));

        if (in_array(self::VARIANT_SIZE, array_column($facets, 'k'), true) || count($values) < 2 || count($sizes) < self::MIN_PAGE_SIZED) {
            return [];
        }

        usort($values, fn (string $a, string $b): int => (float) $a <=> (float) $b);
        $at = count($facets);
        $facets[] = ['k' => self::VARIANT_SIZE, 'l' => (string) __('search::storefront.facet_size', [], $locale), 'v' => array_map(fn (string $v): array => ['k' => $v, 'l' => $v], $values)];
        $code = array_flip($values);

        return array_map(fn (array $zs): array => array_values(array_unique(array_map(fn (float $z): int => $at * 1000 + $code[self::sizeText($z)], $zs))), $sizes);
    }

    /** 54.0 is "54", 54.5 is "54.5": a size as a page writes it. */
    private static function sizeText(float $size): string
    {
        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.');
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
    private static function actions(string $locale, bool $hasSize, bool $sizeFilter = false): array
    {
        // "מידה 52" in a shop whose variants have sizes is its size filter (queryFacets), not a number near 52.
        $registry = array_values(array_filter(self::registry(), fn (array $entry): bool => ($hasSize || ($entry['needs'] ?? null) !== 'size')
            && ! ($sizeFilter && ($entry['unless'] ?? null) === 'size_filter')));

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
            ->filter(fn (AssistantAnswer $a): bool => ($a->product === null || $a->product->removed_at === null) && ShownQuestion::fit(ShownQuestion::text($a)))
            ->map(fn (AssistantAnswer $a): array => [
                'id' => $a->id,
                'store' => $a->product_id === null && $a->content_id === null,
                'kind' => $a->product_id !== null ? 'product' : ($a->content_id !== null ? 'content' : 'store'),
                'asked' => (int) $a->asked_count,
                // shown to other shoppers as a question of the store: its slips mended, written cleanly
                'question' => ShownQuestion::text($a),
                'answer' => mb_substr((string) $a->answer, 0, 400),
                'sources' => $a->product !== null
                    ? [['title' => $a->product->title, 'url' => $a->product->url]]
                    : ($a->content !== null ? [['title' => $a->content->title, 'url' => $a->content->url]] : array_map(fn (array $s): array => ['title' => $s['title'], 'url' => $s['url'] ?? null], (array) $a->sources)),
            ])
            ->values()->all();
    }

    /**
     * The store's own product fields as filters, whatever kind of store it is: a field of its feed
     * (a custom field, as WordPress keeps a book's "מחבר") that at least field_filter_min_percent of
     * the products have, with two to field_filter_max_values short values that repeat (a code or a
     * number each product has its own of narrows nothing). Named as the store names it; the values
     * the most used first. Read from the words too: "ספרים של תומס סואל" picks his.
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets  appended to
     *                                                                                           A field named like a filter the scan already made (its "מחבר" from what it read, on 18 books) takes its place
     *                                                                                           when the field says it for more products (196): that filter is left without values.
     * @param  array<string, array<string, mixed>>  $read  the scan's facts by product (their codes in "f")
     * @return array<string, list<int>> the codes of each product's values, by product id
     */
    private function fieldFacets(array &$facets, string $shopId, array $read = []): array
    {
        $minShare = (int) Settings::get('search.field_filter_min_percent', $shopId) / 100;
        $maxValues = min(999, (int) Settings::get('search.field_filter_max_values', $shopId));
        $named = array_map(fn (array $f): string => mb_strtolower($f['l']), $facets);
        $covered = [];
        foreach ($read as $productId => $facts) {
            foreach ((array) ($facts['f'] ?? []) as $code) {
                $covered[intdiv((int) $code, 1000)][(string) $productId] = true;
            }
        }
        $this->fieldValues = [];
        $fields = [];
        $total = 0;

        foreach (CatalogProduct::query()->active()->orderBy('id')->lazy(200) as $product) {
            $total++;

            foreach ((array) ($product->payload['meta'] ?? []) as $key => $value) {
                $key = trim((string) $key);
                $value = is_scalar($value) ? trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';

                // Not a field of the system ("_price", Shopify's "custom.show_on_website"), nor a value that is a number,
                // an address or a paragraph. A field named for shoppers has words in it ("מחבר", "Author").
                if ($key === '' || str_starts_with($key, '_') || preg_match('/[._]/', $key) === 1 || mb_strlen($key) > 40 || $value === '' || mb_strlen($value) > self::FIELD_VALUE_CHARS
                    || preg_match('/^[\d\s.,:\/-]+$/u', $value) === 1 || preg_match('~^https?://~i', $value) === 1) {
                    continue;
                }

                $fields[$key][(string) $product->id] = $value;
            }
        }

        $products = [];

        foreach ($fields as $key => $byProduct) {
            $counts = array_count_values($byProduct);
            arsort($counts);

            // A yes or no ("true", "כן") says how the store keeps it, not what the shopper chooses between.
            $yesNo = array_diff(array_map('mb_strtolower', array_keys($counts)), ['yes', 'no', 'true', 'false', 'כן', 'לא', 'on', 'off']) === [];
            if ($yesNo || $total === 0 || count($byProduct) / $total < $minShare || count($counts) < 2 || count($counts) > $maxValues
                || count($counts) > count($byProduct) * self::FIELD_MAX_DISTINCT) {
                continue;
            }

            $same = array_search(mb_strtolower($key), $named, true);
            if ($same !== false) {
                if (count($covered[$same] ?? []) >= count($byProduct)) {
                    continue;
                }
                $facets[$same]['v'] = [];
            }

            $at = count($facets);
            $codes = [];
            $values = [];
            foreach (array_keys($counts) as $i => $value) {
                $codes[(string) $value] = $at * 1000 + $i;
                $values[] = ['k' => (string) $i, 'l' => (string) $value];
            }
            $facets[] = ['k' => 'field:'.$key, 'l' => $key, 'v' => $values];

            foreach ($byProduct as $productId => $value) {
                $products[$productId][] = $codes[$value];
                $this->fieldValues[(string) $productId][] = $value;
            }
        }

        return $products;
    }

    /**
     * The tags sure enough to narrow by (the Taxonomy module, where the shop has them as filters), a
     * filter for each group, after the store's own. A value the store already offers under the same
     * name (its category "ביוגרפיות" and the tag "ביוגרפיות") is the store's: not shown twice.
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets  appended to
     * @return array<string, list<int>> the codes of each product's tags, by product id
     */
    private function tagFacets(array &$facets, string $shopId, string $locale): array
    {
        if (! app()->bound(ProductTags::class)) {
            return [];
        }

        $offered = [];
        foreach ($facets as $facet) {
            foreach ($facet['v'] ?? [] as $value) {
                $offered[mb_strtolower(trim((string) $value['l']))] = true;
            }
        }

        $products = [];

        foreach (app(ProductTags::class)->filters($shopId, $locale) as $group) {
            $values = array_values(array_filter($group['values'], fn (array $v): bool => ! isset($offered[mb_strtolower(trim($v['label']))])));
            if (count($values) < 2) {
                continue;
            }

            $at = count($facets);
            $facets[] = ['k' => 'tag:'.$group['key'], 'l' => $group['label'], 'v' => array_map(fn (array $v): array => ['k' => $v['key'], 'l' => $v['label']], $values)];

            foreach ($values as $i => $value) {
                foreach ($value['products'] as $id) {
                    $products[$id][] = $at * 1000 + $i;
                }
            }
        }

        return $products;
    }

    /**
     * The store's categories as one more filter, appended to the facets: each category with at least
     * two products, the most used first, at most MAX_CATEGORIES. A shopper picks it in the filters; one
     * of a single word is never read from the words ("ברגים" still finds screws outside its category),
     * one of several words, all typed, is ("ספרי מופת מתורגמים").
     *
     * @param  list<array{k: string, l: string, v: list<array{k: string, l: string}>}>  $facets  appended to
     * @return array<string, list<int>> the codes of each product's categories, by product id
     */
    private function categoryFacet(array &$facets, string $locale): array
    {
        $used = DB::table('catalog_category_product as cp')
            ->join('catalog_products as p', 'p.id', '=', 'cp.product_id')
            ->where('p.shop_id', $this->tenant->id())->whereNull('p.removed_at')
            ->select('cp.category_id')->selectRaw('count(*) as n')->groupBy('cp.category_id')
            ->havingRaw('count(*) >= '.self::CATEGORY_MIN_PRODUCTS)->orderByRaw('count(*) desc')->orderBy('cp.category_id')->limit(200)
            ->pluck('n', 'category_id');
        $total = CatalogProduct::query()->active()->count();

        $names = CatalogCategory::query()->whereIn('id', $used->keys()->all())->pluck('name', 'id');
        $at = count($facets);
        $values = [];
        $index = [];

        foreach ($used as $id => $count) {
            $name = trim((string) ($names[$id] ?? ''));

            // A filter of the kinds a shopper narrows by, the most used: never a store's collection kept as
            // an address ("the-summer-edit"), a campaign ("blackfriday", "סוף-שנה-sale", "הגיעו לאחרונה"), or
            // one that holds nearly every product and so narrows nothing.
            if ($name === '' || isset($index[(string) $id]) || ! self::shopperCategory($name) || ($total > 0 && $count / $total >= self::CATEGORY_MAX_SHARE)) {
                continue;
            }

            if (count($values) >= self::MAX_CATEGORIES) {
                break;
            }

            $index[(string) $id] = $at * 1000 + count($values);
            $values[] = ['k' => (string) $id, 'l' => $name];
        }

        if (count($values) < 2) {
            return [];
        }

        $facets[] = ['k' => 'category', 'l' => (string) __('search::storefront.facet_category', [], $locale), 'v' => $values];
        $products = [];

        foreach (DB::table('catalog_category_product')->whereIn('category_id', array_keys($index))->orderBy('product_id')->get(['product_id', 'category_id']) as $row) {
            $products[(string) $row->product_id][] = $index[(string) $row->category_id];
        }

        return $products;
    }

    /**
     * What the scan learned and approved each product is, is for and is tagged with, as words in the
     * store's language: a search for what it does finds it even when its name does not say so.
     *
     * @return array<string, list<string>> by product id
     */
    private function learnedWords(string $locale): array
    {
        $words = [];
        $was = app()->getLocale();
        app()->setLocale($locale);

        try {
            EnrichmentFact::query()->current()->where('status', FactStatus::Approved)->whereNotNull('product_id')
                ->whereIn('kind', [FactKind::Type, FactKind::Use, FactKind::Tag])
                ->with('vocabulary')
                ->lazyById(2000)->each(function (EnrichmentFact $fact) use (&$words): void {
                    if ($fact->kind === FactKind::Tag && str_starts_with((string) $fact->key, 'ask_')) {
                        return;
                    }

                    $label = trim($fact->valueLabel());

                    if ($label !== '') {
                        $words[(string) $fact->product_id][] = $label;
                    }
                });
        } finally {
            app()->setLocale($was);
        }

        return $words;
    }

    /** @param  list<string>  $learned  what the scan learned it is and is for, right after its categories */
    private function productWords(CatalogProduct $product, array $learned = []): array
    {
        $words = array_filter([(string) $product->brand, (string) $product->sku]);

        foreach ($product->categories as $category) {
            $words[] = (string) $category->name;
        }

        array_push($words, ...$learned);

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
