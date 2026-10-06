<?php

namespace App\Modules\Search\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Support\HebrewSearch;

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

    private const MAX_KEYWORDS = 400;

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

    private function build(RunContext $run, string $shopId): void
    {
        $synonyms = $this->synonyms();
        $items = [];
        $counts = ['product' => 0, 'content' => 0, 'category' => 0, 'synonyms' => count($synonyms)];

        foreach (CatalogProduct::query()->active()->with('categories:id,name')->orderBy('id')->lazy(200) as $product) {
            $items[] = $this->record('p:'.$product->external_id, 'product', $product->title, $this->productWords($product), $synonyms, [
                'url' => $product->url,
                'img' => $product->image_url,
                's' => $product->in_stock ? 1 : 0,
                'buy' => $product->type === 'simple' && $product->purchasable ? 1 : 0,
            ]);
            $counts['product']++;
        }

        foreach (CatalogContent::query()->active()->orderBy('id')->lazy(200) as $content) {
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

        $json = (string) json_encode(['v' => self::VERSION, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = substr(hash('sha256', $json), 0, 20);

        SearchIndex::query()->updateOrCreate(['shop_id' => $shopId], [
            'hash' => $hash,
            'items' => $json,
            'counts' => $counts,
            'built_at' => now(),
        ]);

        $run->output(['counts' => $counts, 'hash' => $hash, 'bytes' => strlen($json)])
            ->summary('search::runs.indexed', [
                'products' => (string) $counts['product'],
                'content' => (string) $counts['content'],
                'categories' => (string) $counts['category'],
            ]);
    }

    /** @return list<string> */
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
}
