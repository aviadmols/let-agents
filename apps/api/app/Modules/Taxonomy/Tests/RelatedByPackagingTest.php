<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Related;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What two products share about their packaging or their form says nothing of what a shopper would like next
 * (Sela Meir, 28.10: a bundle on 7.10 had every other bundle for its alike, by "באנדל ומארז" and the store's own
 * "באנדל", before the books on 7.10).
 */
final class RelatedByPackagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bundle_on_a_subject_is_like_the_books_on_it_not_like_every_other_bundle(): void
    {
        $shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        $tenant = app(TenantContext::class);

        $found = $tenant->run($shop->id, function () use ($shop): array {
            $ids = [];
            // the shop's own name and its imprint, as store tags on the two bundles and the series book
            foreach (['757' => ['ספרי השבעה באוקטובר', ['באנדל', 'סלע מאיר', 'ספריית שיבולת']], '755' => ['ספרי דאגלס מאריי במבצע', ['באנדל', 'סלע מאיר', 'ספריית שיבולת']], '655' => ['להבין את ה-7 באוקטובר', []], '600' => ['מרד הגנרלים', []], '9' => ['ספר שנמחק', []]] as $id => [$title, $tags]) {
                $ids[$id] = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => $tags],
                    'removed_at' => $id === '9' ? now() : null])->id;
            }
            CatalogProduct::query()->whereKey($ids['9'])->update(['removed_at' => now()]);
            $group = fn (string $key, string $kind, bool $recommend): TaxonomyGroup => TaxonomyGroup::query()->create(['shop_id' => $shop->id, 'key' => $key, 'label_he' => $key, 'label_en' => $key, 'kind' => $kind, 'recommend' => $recommend, 'version' => 1]);
            $tag = fn (TaxonomyGroup $g, string $key, string $he): TaxonomyTag => TaxonomyTag::query()->create(['shop_id' => $shop->id, 'group_id' => $g->id, 'key' => $key, 'label_he' => $he, 'label_en' => $key, 'version' => 1]);
            [$topic, $genre, $format] = [$group('topic', 'inferred', true), $group('genre', 'inferred', true), $group('format', 'factual', false)];
            $oct = $tag($topic, 'oct7', '7 באוקטובר ומלחמת חרבות ברזל');
            $politics = $tag($topic, 'politics', 'פוליטיקה');
            // packaging filed as a genre, read from the picture: a bank's slip
            $bundle = $tag($genre, 'bundle', 'באנדל ומארז');
            $print = $tag($format, 'print', 'ספר מודפס');
            $give = fn (TaxonomyTag $t, string $p, string $source = 'catalog') => TaxonomyAssignment::query()->create([
                'shop_id' => $shop->id, 'subject_type' => 'product', 'subject_id' => $ids[$p], 'tag_id' => $t->id, 'source' => $source,
                'confidence' => 0.9, 'weight' => 1.0, 'status' => 'kept', 'checked' => 'held', 'input_hash' => 'h', 'bank_version' => 1,
            ]);
            $give($oct, '757');
            $give($bundle, '757', 'picture');
            $give($print, '757');
            $give($politics, '755');
            $give($bundle, '755', 'picture');
            $give($print, '755');
            $give($oct, '655');
            $give($print, '655');
            $give($politics, '600');
            $give($print, '600');
            // a product the store no longer sells: its tags count for no one, and make no tag rarer or commoner
            $give($oct, '9');

            $titles = CatalogProduct::query()->pluck('title', 'id');

            return array_map(fn (array $r): array => ['title' => $titles[$r['id']], 'shared' => $r['shared']], (new Related)->to($ids['757'], 3));
        });

        $this->assertSame('להבין את ה-7 באוקטובר', $found[0]['title'], 'the book on its subject first, not the other bundle');
        $this->assertContains('7 באוקטובר ומלחמת חרבות ברזל', $found[0]['shared']);
        $this->assertNotContains('ספר שנמחק', array_column($found, 'title'));
        $this->assertTrue(Related::packaging('באנדל ומארז'));
        $this->assertTrue(Related::packaging('Box set'));
        $this->assertFalse(Related::packaging('7 באוקטובר ומלחמת חרבות ברזל'));
        $this->assertFalse(Related::packaging('סטודנטים'), 'a word that only starts with one');
    }
}
