<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Actions\FixRanges;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Exact;
use App\Modules\Taxonomy\Support\Meaning;
use App\Modules\Taxonomy\Support\TagWords;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_a_range_its_own_number_is_outside_of_moves_to_the_range_that_holds_it(): void
    {
        Event::fake([CatalogProductsChanged::class]);

        [$moved, $after] = $this->tenant(function (): array {
            $days = $this->group('trip_length', 'Trip length', 'אורך הטיול');
            $carats = $this->group('diamond_weight', 'Total diamond weight', 'משקל היהלומים');
            $tag = fn (TaxonomyGroup $g, string $key, string $en, string $he): TaxonomyTag => TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $g->id, 'key' => $key, 'label_en' => $en, 'label_he' => $he, 'version' => 1]);
            $four = $tag($days, 'd4', '4–5 days', '4–5 ימים');
            $six = $tag($days, 'd6', '6–8 days', '6–8 ימים');
            $nine = $tag($days, 'd9', '9–11 days', '9–11 ימים');
            $twelve = $tag($days, 'd12', '12+ days', '12 ימים ומעלה');
            $upTo = $tag($carats, 'c0', 'Up to 0.25 ct', 'עד 0.25 קארט');
            $quarter = $tag($carats, 'c1', '0.25 to 0.5 ct', '0.25 עד 0.5 קארט');
            $half = $tag($carats, 'c2', '0.5 to 1 ct', '0.5 עד 1 קארט');
            $one = $tag($carats, 'c3', '1 to 2 ct', '1 עד 2 קארט');
            $over = $tag($carats, 'c4', 'Over 2 ct', 'מעל 2 קארט');
            $dayTags = [$four, $six, $nine, $twelve];
            $caratTags = [$upTo, $quarter, $half, $one, $over];

            // the stores' own slips
            $this->assertSame($six, Exact::fitting($four, '6-Day', $dayTags));
            $this->assertSame($nine, Exact::fitting($six, '9 Days', $dayTags));
            $this->assertSame($half, Exact::fitting($quarter, 'diamonds_total_carat: 0.75 ct', $caratTags));
            $this->assertSame($one, Exact::fitting($half, '1.15 קארט', $caratTags));
            $this->assertSame($upTo, Exact::fitting($quarter, '0.2 קארט', $caratTags));
            $this->assertSame($one, Exact::fitting($over, '2 ct', $caratTags));
            // what stays: a number in its range, nights, two numbers, a number two ranges hold
            $this->assertSame($twelve, Exact::fitting($twelve, 'takes around 45 to 60 days to finish', $dayTags));
            $this->assertSame($nine, Exact::fitting($nine, 'ten-night trip', $dayTags));
            $this->assertSame($four, Exact::fitting($four, 'In eight to eleven unforgettable days', $dayTags));
            $this->assertSame($upTo, Exact::fitting($upTo, '0.5 קארט', $caratTags));

            $product = fn (string $id): string => CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "Tour {$id}",
                'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []])->id;
            $give = fn (string $subject, TaxonomyTag $t, string $evidence, ?string $checked = 'held') => TaxonomyAssignment::query()->create(['shop_id' => $this->shop->id, 'subject_type' => 'product',
                'subject_id' => $subject, 'tag_id' => $t->id, 'source' => 'catalog', 'evidence' => $evidence, 'confidence' => 0.9, 'weight' => 1.0, 'status' => 'kept', 'checked' => $checked, 'input_hash' => 'h', 'bank_version' => 1]);
            [$a, $b, $c] = [$product('1'), $product('2'), $product('3')];
            $give($a, $four, '6-Day South Africa Itinerary');
            $give($b, $four, '6-Day');
            $give($b, $six, '6 days in the Cape'); // it has the right one already: the wrong one goes
            $give($c, $four, '6-Day', TaxonomyAssignment::TEAM_KEPT); // the team kept it so: it stays

            return [app(FixRanges::class)->handle($this->shop->id), TaxonomyAssignment::query()->orderBy('subject_id')->get(['subject_id', 'tag_id'])->map(fn ($x): array => [$x->subject_id, $x->tag_id])->all()];
        });

        $this->assertCount(2, $moved);
        $this->assertSame(['4–5 days', '6–8 days'], [$moved[0]['from'], $moved[0]['to']]);
        $tags = $this->tenant(fn (): array => TaxonomyTag::query()->pluck('id', 'key')->all());
        $products = $this->tenant(fn (): array => CatalogProduct::query()->pluck('id', 'external_id')->all());
        $this->assertEqualsCanonicalizing([[$products['1'], $tags['d6']], [$products['2'], $tags['d6']], [$products['3'], $tags['d4']]], $after);
        Event::assertDispatched(CatalogProductsChanged::class, fn (CatalogProductsChanged $e): bool => $e->shopId === $this->shop->id);

        // once is enough: nothing left to move
        $this->assertSame([], app(FixRanges::class)->handle($this->shop->id));
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
