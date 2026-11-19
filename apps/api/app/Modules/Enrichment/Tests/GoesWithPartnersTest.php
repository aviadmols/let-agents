<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Contracts\GoesWithPartners;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The products the store itself pairs with a product (GoesWithPartners): its complement relations, only where the shop keeps them. */
final class GoesWithPartnersTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_the_stores_own_complement_relations_are_read_only_where_the_shop_keeps_them(): void
    {
        $this->buildShop();
        $drill = $this->product('d1', 'מקדחה רוטטת', 'מקדחה.', []);
        $glue = $this->product('g1', 'דבק עץ', 'דבק.', []);
        $bits = $this->product('b1', 'סט מקדחים', 'מקדחים.', []);
        $this->inShop(function () use ($drill, $glue, $bits): void {
            foreach ([[$glue, 150, 'merchant'], [$bits, 200, 'bought_together']] as [$related, $score, $source]) {
                EnrichmentProductRelation::query()->create(['shop_id' => $this->shop->id, 'product_id' => $drill->id, 'related_product_id' => $related->id,
                    'kind' => RelationKind::Complement, 'source' => $source, 'score' => $score, 'reasons' => [], 'computed_at' => now()]);
            }
            EnrichmentProductRelation::query()->create(['shop_id' => $this->shop->id, 'product_id' => $drill->id, 'related_product_id' => $bits->id,
                'kind' => RelationKind::Alternative, 'source' => 'same_type', 'score' => 90, 'reasons' => [], 'computed_at' => now()]);
        });

        Features::override('enrichment.relations', true, $this->shop->id);
        $this->assertSame([
            ['external_id' => 'b1', 'score' => 200, 'source' => 'bought_together'],
            ['external_id' => 'g1', 'score' => 150, 'source' => 'merchant'],
        ], $this->inShop(fn () => app(GoesWithPartners::class)->complementsOf($this->shop->id, 'd1')), 'the complements, best first; an alternative is none');

        Features::override('enrichment.relations', false, $this->shop->id);
        $this->assertSame([], $this->inShop(fn () => app(GoesWithPartners::class)->complementsOf($this->shop->id, 'd1')), 'a shop without relations has none to read');
    }
}
