<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store's categories mix kinds (a theme, a meal, a country each); where its vocabulary is its expert bank, the bank has
 * sorted the same names into filters of their own (a destination, a theme), and the category filter offers only what the
 * bank does not say, or nothing when too little is left. The names stay words the products are found by. Any other
 * vocabulary leaves the category filter as it was.
 */
final class CategoriesBesideExpertBankTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'en']);

        $this->inShop(function (): void {
            $categories = [];
            foreach (['italy' => 'Italy', 'japan' => 'Japan', 'iceland' => 'Iceland', 'family' => 'Family Trips', 'kosher' => 'Kosher Travel', 'heritage' => 'Jewish Heritage'] as $id => $name) {
                $categories[$id] = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'name' => $name, 'path' => [$name], 'depth' => 0, 'hash' => $id]);
            }
            // twelve tours, a country each and a theme each: every category holds four
            $themes = ['family', 'kosher', 'heritage'];
            $n = 0;
            foreach (['italy', 'japan', 'iceland'] as $country) {
                foreach ([0, 1, 2, 3] as $i) {
                    $n++;
                    $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => (string) $n, 'type' => 'simple', 'status' => 'publish', 'title' => "Tour number {$n}",
                        'url' => "https://www.store.test/p/{$n}", 'price' => '20', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$n}", 'payload' => ['description' => 'Flights and hotels included.']]);
                    $product->categories()->attach([$categories[$country]->id, $categories[$themes[($n - 1) % 3]]->id]);
                }
            }
        });
    }

    public function test_beside_an_expert_bank_the_category_filter_leaves_out_what_the_bank_says(): void
    {
        $this->vocabulary(fromBank: true, destinations: ['Italy' => ['italy'], 'Japan' => ['japan']]);

        $this->assertEqualsCanonicalizing(['Iceland', 'Family Trips', 'Kosher Travel', 'Jewish Heritage'], $this->categoryFilter());
        $items = $this->items();
        $this->assertStringContainsString('italy', mb_strtolower($items['p:1']['kw']), 'a category the bank says is still a word its products are found by');
    }

    public function test_a_category_the_bank_says_in_its_own_words_goes_too_and_under_the_setting_none_is_left(): void
    {
        // "Family Trips" whole in the theme's words; the label alone says "Iceland"
        $this->vocabulary(fromBank: true, destinations: ['Italy' => ['italy'], 'Japan' => ['japan'], 'Iceland' => ['reykjavik']], themes: ['Family' => ['family trips'], 'Heritage' => ['heritage tour']]);

        $this->assertNull($this->categoryFilter(), 'two categories left, fewer than three: no category filter');
        $this->assertStringContainsString('family trips', mb_strtolower($this->items()['p:1']['kw']));

        Settings::set('search.category_filter_min_beside_bank', 2, $this->shop->id);
        $this->assertEqualsCanonicalizing(['Kosher Travel', 'Jewish Heritage'], $this->categoryFilter(), '"Jewish Heritage" is not "heritage tour": only a whole name goes');
    }

    public function test_a_store_whose_vocabulary_is_no_expert_bank_keeps_its_category_filter(): void
    {
        $this->vocabulary(fromBank: false, destinations: ['Italy' => ['italy'], 'Japan' => ['japan'], 'Iceland' => ['iceland']], themes: ['Family' => ['family trips'], 'Kosher' => ['kosher travel']]);

        $this->assertEqualsCanonicalizing(['Italy', 'Japan', 'Iceland', 'Family Trips', 'Kosher Travel', 'Jewish Heritage'], $this->categoryFilter());
    }

    /**
     * @param  array<string, list<string>>  $destinations  label => words
     * @param  array<string, list<string>>  $themes  label => words
     */
    private function vocabulary(bool $fromBank, array $destinations, array $themes = []): void
    {
        $values = fn (array $of): array => array_map(fn (string $label, array $words): array => [
            'key' => strtolower(preg_replace('/\W+/', '_', $label)), 'label' => ['he' => $label, 'en' => $label], 'words' => $words,
            'patterns' => array_map(fn (string $word): string => str_replace(' ', '[\s\-]+', preg_quote($word, '~')), $words),
        ], array_keys($of), $of);
        $attributes = [['key' => 'destination', 'type' => 'enum', 'multiple' => true, 'label' => ['he' => 'Destination', 'en' => 'Destination'], 'values' => $values($destinations)]];
        if ($themes !== []) {
            $attributes[] = ['key' => 'theme', 'type' => 'enum', 'multiple' => true, 'label' => ['he' => 'Theme', 'en' => 'Theme'], 'values' => $values($themes)];
        }
        $definition = [
            'schema_version' => 1, 'key' => $fromBank ? 'expert_bank' : 'tours', 'name' => ['he' => 'Tours', 'en' => 'Tours'], 'root_category_external_id' => '*',
            'product_types' => [['key' => 'tour', 'label' => ['he' => 'Tour', 'en' => 'Tour'], 'patterns' => ['tour']]],
            'attributes' => $attributes,
        ] + ($fromBank ? ['expert_bank' => '01bank'] : []);

        $this->inShop(fn () => EnrichmentVocabulary::query()->create([
            'shop_id' => $this->shop->id, 'key' => $definition['key'], 'version' => 1, 'root_category_external_id' => '*',
            'definition' => $definition, 'definition_hash' => hash('sha256', (string) json_encode($definition)), 'active' => true,
        ]));
    }

    /** @return list<string>|null the category filter's names, in its order; null when there is none */
    private function categoryFilter(): ?array
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $data = json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true);
        $facet = collect($data['facets'])->firstWhere('k', 'category');

        return $facet === null ? null : array_column($facet['v'], 'l');
    }

    /** @return array<string, array<string, mixed>> the last index's items, by id */
    private function items(): array
    {
        return collect(json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true)['items'])->keyBy('id')->all();
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
