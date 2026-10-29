<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Exact;
use App\Modules\Taxonomy\Support\Meaning;
use App\Modules\Taxonomy\Support\TagWords;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tag that is a range keeps the exact value its passage gives, shown and searched beside it: read by code from what
 * was already kept, so the tags given before it are read as well, and nothing is tagged again. The ranges and the
 * passages here are the stores' own (Gil Travel's trip lengths, Rocks and Gold's diamond weights).
 */
final class ExactValuesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
    }

    public function test_the_exact_value_is_the_one_number_in_the_range_by_its_unit(): void
    {
        $this->tenant(function (): void {
            $days = $this->group('trip_length', 'Trip length', 'אורך הטיול');
            $carats = $this->group('diamond_weight', 'Total diamond weight', 'משקל היהלומים');
            $tag = fn (TaxonomyGroup $g, string $en, string $he): TaxonomyTag => TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $g->id, 'key' => md5($en), 'label_en' => $en, 'label_he' => $he, 'version' => 1]);

            $nine = $tag($days, '9–11 days', '9–11 ימים');
            $twelve = $tag($days, '12+ days', '12 ימים ומעלה');
            $quarter = $tag($carats, '0.25 to 0.5 ct', '0.25 עד 0.5 קארט');
            $upTo = $tag($carats, 'Up to 0.25 ct', 'עד 0.25 קארט');
            $over = $tag($carats, 'Over 2 ct', 'מעל 2 קארט');

            $this->assertSame(['min' => 9.0, 'max' => 11.0, 'unit_en' => 'days', 'unit_he' => 'ימים'], Exact::range($nine));
            $this->assertSame('ימים', Exact::range($twelve)['unit_he']);
            $this->assertSame('קארט', Exact::range($upTo)['unit_he']);

            foreach ([
                [$nine, '10 Day', 10.0], [$nine, '11 Day Rome, Venice, and Florence', 11.0], [$nine, 'עשרה ימים בפורטוגל', 10.0],
                [$nine, 'ten-night trip', null], // nights are not days
                [$nine, '5-Day Stockholm', null], // not in the range
                [$twelve, 'In fourteen days', 14.0], [$twelve, 'Day 1 to Day 12 of a 15 day tour', 15.0],
                [$quarter, 'ששוקלים בסך הכל 0.5 קארט', 0.5], [$quarter, 'two stones of 0.3 ct and 0.4 ct', null], // two fit: not sure
                [$upTo, '0.02 קארט', 0.02], [$over, 'diamonds_total_carat: 6.45 ct', 6.45], [$over, '2 ct', null], // over two is not two
                [$quarter, '0.3', 0.3], // the passage's one number, standing alone
                [$nine, null, null],
            ] as [$t, $evidence, $value]) {
                $this->assertSame($value, Exact::value($t, $evidence), $t->label_en.' / '.$evidence);
            }

            $this->assertSame('10 days', Exact::label($nine, 10.0, 'en'));
            $this->assertSame('10 ימים', Exact::label($nine, 10.0, 'he'));
            $this->assertSame('0.5 קארט', Exact::label($quarter, 0.5, 'he'));

            // a value already (a karat), a theme, a group of a reader's judgement: no range
            $karat = $this->group('gold_karat', 'Gold karat', 'קראט זהב');
            $this->assertNull(Exact::range($tag($karat, '14K gold', 'זהב 14 קארט')));
            $theme = $this->group('period', 'Period', 'תקופה', 'inferred');
            $this->assertNull(Exact::value($tag($theme, '1–3 days', '1–3 ימים'), '2 days'));
            $price = $this->group('price_band', 'Price', 'מחיר');
            $this->assertNull(Exact::range($tag($price, '100–200 ILS', '100–200 ש"ח')));
        });
    }

    public function test_a_tag_given_before_shows_and_is_searched_by_its_exact_value(): void
    {
        Features::override('taxonomy.in_search', true, $this->shop->id);

        [$trip, $tagged] = $this->tenant(function (): array {
            $days = $this->group('trip_length', 'Trip length', 'אורך הטיול');
            $nine = TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $days->id, 'key' => 'days_9_11', 'label_en' => '9–11 days', 'label_he' => '9–11 ימים', 'version' => 1]);
            $trip = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => '10 Day Romania',
                'url' => 'https://store.test/p/1', 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []])->id;
            TaxonomyAssignment::query()->create(['shop_id' => $this->shop->id, 'subject_type' => 'product', 'subject_id' => $trip, 'tag_id' => $nine->id, 'source' => 'catalog',
                'evidence' => '10 Day', 'confidence' => 0.9, 'weight' => 1.0, 'status' => 'kept', 'checked' => 'held', 'input_hash' => 'h', 'bank_version' => 1]);

            return [$trip, app(Meaning::class)->of('product', $trip, 'he')];
        });

        $this->assertSame('9–11 ימים', $tagged[0]['label']);
        $this->assertSame('10 ימים', $tagged[0]['exact']);

        $words = $this->tenant(fn (): array => app(TagWords::class)->tagsByProduct($this->shop->id));
        $this->assertContains(['10 ימים', 1.0], $words[$trip]);
        $this->assertContains(['10 days', 1.0], $words[$trip]);
        $this->assertContains(['9–11 ימים', 1.0], $words[$trip]);

        $html = view('enrichment::merchant.scan-meaning', ['meaning' => $tagged])->render();
        $this->assertStringContainsString('data-meaning-exact', $html);
        $this->assertStringContainsString('10 ימים', $html);
    }

    private function group(string $key, string $en, string $he, string $kind = TaxonomyGroup::FACTUAL): TaxonomyGroup
    {
        return TaxonomyGroup::query()->create(['shop_id' => $this->shop->id, 'key' => $key, 'label_en' => $en, 'label_he' => $he, 'kind' => $kind, 'searchable' => true, 'filter' => true, 'version' => 1]);
    }

    private function tenant(callable $work): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $work);
    }
}
