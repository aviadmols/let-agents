<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The nightly scores applied to a product page. */
final class LearnedOrderTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private CatalogProduct $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        // These sections are built here from the relations computed at night.
        Features::override('enrichment.relations', true, $this->shop->id);
        $this->page = $this->product('10', 'מסור אנכי', 'x', []);

        foreach (['21', '22', '23', '24', '25', '26'] as $i => $id) {
            $this->relation($id, 'complement', 100 - $i);
        }
        $this->relation('41', 'family', 50);
        $this->relation('42', 'family', 40);
        $this->relation('31', 'alternative', 10);
    }

    public function test_without_scores_the_built_order_stands_with_the_product_limit(): void
    {
        // A merchant link to another size of the same product, scored above every complement.
        $this->inShop(fn () => EnrichmentProductRelation::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->page->id,
            'related_product_id' => CatalogProduct::query()->where('external_id', '41')->value('id'),
            'kind' => 'complement', 'source' => 'merchant_cross_sell', 'score' => 500, 'reasons' => [], 'computed_at' => now(),
        ]));

        $sections = $this->sections();

        $this->assertSame(['complement', 'family', 'alternatives'], array_column($sections, 'candidate'));
        $this->assertSame(['21', '22', '23', '24'], array_column($sections[0]['products'], 'id'), 'spares are not shown, and another size is not a complement');
    }

    public function test_what_worked_comes_first_and_what_was_never_clicked_makes_room(): void
    {
        $this->score(AnalyticsScore::SCOPE_MODULE, 'complement', 0.1);
        $this->score(AnalyticsScore::SCOPE_MODULE, 'alternatives', 0.3);
        // On this page complements work better than they do across the shop.
        $this->score(AnalyticsScore::SCOPE_PAGE, 'complement', 0.5, opens: 45);
        $this->score(AnalyticsScore::SCOPE_RELATED, 'complement', 1.2, related: '23');
        $this->score(AnalyticsScore::SCOPE_PAGE, 'family', 0.2, opens: 60);
        $this->score(AnalyticsScore::SCOPE_RELATED, 'family', 0.9, related: '42');

        $sections = collect($this->sections())->keyBy('candidate');

        // Complement by its page score, alternatives by the shop-wide one, family by its page score.
        $this->assertSame(['complement', 'alternatives', 'family'], $sections->keys()->all());
        $this->assertSame(['23', '25', '26'], array_column($sections['complement']['products'], 'id'), '21, 22 and 24 were shown 45 times and never clicked');
        $this->assertSame(['42', '41'], array_column($sections['family']['products'], 'id'), 'other sizes are reordered, never dropped');
    }

    public function test_a_section_opened_often_with_nothing_clicked_is_dropped_and_an_unseen_one_gets_seen(): void
    {
        $this->score(AnalyticsScore::SCOPE_MODULE, 'complement', 0.1);
        $this->score(AnalyticsScore::SCOPE_PAGE, 'complement', 0.01, opens: 5);
        $this->score(AnalyticsScore::SCOPE_PAGE, 'alternatives', 0.05, opens: 40);

        // Family has no score anywhere: it gets the best known one (0.1), above complement here.
        $this->assertSame(['family', 'complement'], array_column($this->sections(), 'candidate'));
    }

    /** @return list<array<string, mixed>> */
    private function sections(): array
    {
        return app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he')['sections'];
    }

    private function relation(string $externalId, string $kind, int $score): void
    {
        $related = $this->product($externalId, 'מוצר '.$externalId, 'x', []);

        $this->inShop(fn () => EnrichmentProductRelation::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->page->id, 'related_product_id' => $related->id,
            'kind' => $kind, 'source' => 'test', 'score' => $score, 'reasons' => [], 'computed_at' => now(),
        ]));
    }

    private function score(string $scope, string $candidate, float $score, int $opens = 0, string $related = ''): void
    {
        $this->inShop(fn () => AnalyticsScore::query()->create([
            'shop_id' => $this->shop->id, 'scope' => $scope, 'candidate' => $candidate,
            'page_type' => $scope === AnalyticsScore::SCOPE_MODULE ? '' : 'product',
            'page_external_id' => $scope === AnalyticsScore::SCOPE_MODULE ? '' : '10',
            'related_external_id' => $related, 'exposures' => $scope === AnalyticsScore::SCOPE_RELATED ? $opens : 100,
            'opens' => $opens, 'clicks' => 0, 'adds' => 0, 'purchases' => 0, 'value' => 1, 'score' => $score, 'computed_at' => now(),
        ]));
    }
}
