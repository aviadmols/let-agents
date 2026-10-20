<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sela: 196 of its 199 books have a "מחבר" field in the feed, and the store asked to filter by it. A
 * field most products have, whose values repeat, is a filter named as the store names it, in any kind
 * of store; a code each product has its own of, a number, or a field of the system is not.
 */
final class FieldFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_field_most_products_have_is_a_filter_named_as_the_store_names_it(): void
    {
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        $books = [
            '1' => ['מחבר' => 'ג\'ורדן פיטרסון', 'מסת"ב' => 'A-1', '_edit_lock' => 'x', 'עמודים' => '320'],
            '2' => ['מחבר' => 'ג\'ורדן פיטרסון', 'מסת"ב' => 'A-2', '_edit_lock' => 'x', 'עמודים' => '210'],
            '3' => ['מחבר' => 'תומס סואל', 'מסת"ב' => 'A-3', '_edit_lock' => 'x', 'עמודים' => '400'],
            '4' => ['מחבר' => 'דאגלס מאריי', 'מסת"ב' => 'A-4', '_edit_lock' => 'x', 'עמודים' => '288'],
            '5' => ['מסת"ב' => 'A-5'],
        ];
        app(TenantContext::class)->run($shop->id, function () use ($shop, $books): void {
            foreach ($books as $id => $meta) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'ספר '.$id, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => ['meta' => $meta]]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $index = $this->index($shop);
        $facet = collect($index['facets'])->firstWhere('k', 'field:מחבר');

        $this->assertNotNull($facet, 'four of five books have an author');
        $this->assertSame('מחבר', $facet['l']);
        $this->assertSame(['ג\'ורדן פיטרסון', 'תומס סואל', 'דאגלס מאריי'], array_column($facet['v'], 'l'), 'the most used first');
        $this->assertSame([], collect($index['facets'])->filter(fn (array $f): bool => in_array($f['k'], ['field:מסת"ב', 'field:_edit_lock', 'field:עמודים'], true))->all(), 'a code of each its own, a field of the system and a number are not filters');

        $at = array_search('field:מחבר', array_column($index['facets'], 'k'), true);
        $records = collect($index['items'])->keyBy('id');
        $this->assertContains($at * 1000, $records['p:1']['f']);
        $this->assertContains($at * 1000, $records['p:2']['f']);
        $this->assertContains($at * 1000 + 1, $records['p:3']['f']);
        $this->assertArrayNotHasKey('f', $records['p:5']);

        $this->assertSame(['3'], $this->found($shop, 'ספר של תומס סואל'), 'an author finds his book, which the title does not name');

        Features::override('search.field_filters', false, $shop->id);
        app(BuildSearchIndex::class)->handle($shop->id);
        $this->assertNull(collect($this->index($shop)['facets'])->firstWhere('k', 'field:מחבר'), 'off: no field filters');
        $this->assertSame(['3'], $this->found($shop, 'ספר של תומס סואל'), 'off: still words of the book');
    }

    /** @return list<string> */
    private function found(Shop $shop, string $query): array
    {
        LoadedIndex::forget();

        return array_column(app(SearchCatalog::class)->handle($shop->id, $query, ['product'], false, null, false)['groups']['product'], 'external_id');
    }

    /** @return array<string, mixed> */
    private function index(Shop $shop): array
    {
        return json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->where('shop_id', $shop->id)->value('items')), true);
    }
}
