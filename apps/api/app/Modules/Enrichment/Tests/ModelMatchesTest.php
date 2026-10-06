<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Enums\MatchKind;
use App\Modules\Retrieval\Enums\MatchRequestStatus;
use App\Modules\Retrieval\Enums\MatchStatus;
use App\Modules\Retrieval\Models\RetrievalMatch;
use App\Modules\Retrieval\Models\RetrievalMatchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the matching model chose and code accepted becomes a relation like any other: kept with
 * its reasons, ranked below evidence and the merchant's own links, checked for stock again.
 */
final class ModelMatchesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_accepted_picks_become_relations_and_refused_ones_do_not(): void
    {
        $this->buildShop();
        $drill = $this->product('10', 'מקדחה נטענת 18V', 'x', []);
        $bits = $this->product('20', 'סט מקדחים לבטון', 'x', []);
        $light = $this->product('11', 'מקדחה נטענת 12V', 'x', []);
        $goggles = $this->product('30', 'משקפי מגן', 'x', []);
        $battery = $this->product('40', 'סוללה 18V', 'x', [], ['in_stock' => false]);

        $this->inShop(function () use ($drill, $bits, $light, $goggles, $battery): void {
            $request = RetrievalMatchRequest::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => $drill->id, 'status' => MatchRequestStatus::Answered,
                'input_hash' => 'x', 'candidates' => [],
            ]);
            $pick = fn (CatalogProduct $related, MatchKind $kind, MatchStatus $status, int $position, ?string $because = null) => RetrievalMatch::query()->create([
                'shop_id' => $this->shop->id, 'request_id' => $request->id, 'product_id' => $drill->id,
                'related_product_id' => $related->id, 'kind' => $kind, 'status' => $status, 'rejected_because' => $because,
                'position' => $position, 'reason' => 'הולך עם המקדחה', 'signals' => ['orders_together' => 3, 'lift' => 4.2],
            ]);

            $pick($bits, MatchKind::Complement, MatchStatus::Accepted, 0);
            $pick($battery, MatchKind::Complement, MatchStatus::Accepted, 1);
            $pick($light, MatchKind::Alternative, MatchStatus::Accepted, 0);
            $pick($goggles, MatchKind::Complement, MatchStatus::Rejected, 2, 'over_limit');
        });

        $run = app(ComputeProductRelations::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);

        $relations = $this->inShop(fn () => EnrichmentProductRelation::query()->where('product_id', $drill->id)->where('source', 'ai_match')->get());

        $this->assertSame([$bits->id], $relations->where('kind', 'complement')->pluck('related_product_id')->all(), 'the sold-out battery and the refused goggles stay out');
        $this->assertSame([$light->id], $relations->where('kind', 'alternative')->pluck('related_product_id')->all());

        $bitsRelation = $relations->firstWhere('related_product_id', $bits->id);
        $this->assertSame(120, $bitsRelation->score);
        $this->assertSame('הולך עם המקדחה', $bitsRelation->reasons['ai_match']);
        $this->assertSame(3, $bitsRelation->reasons['evidence']['orders_together']);
    }
}
