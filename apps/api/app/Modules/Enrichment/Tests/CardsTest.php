<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\BuildCards;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Contracts\ReadsCards;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentCard;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cards every model reads: one product, one page or post, and the store, each one JSON with
 * its facts labelled in the shop's language, built in code and written only when it changed.
 */
final class CardsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_product_page_and_the_store_each_have_one_clear_card(): void
    {
        $this->buildShop();
        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test')['vocabulary'];
        $ring = $this->product('1', 'טבעת רובי בזהב צהוב', "טבעת קלאסית.\nמשובצת אבן רובי טבעית.", [], [
            'on_sale' => true, 'regular_price' => '1290.00', 'price' => '990.00',
            'payload' => ['title' => 'טבעת רובי בזהב צהוב', 'description' => "טבעת קלאסית.\nמשובצת אבן רובי טבעית.", 'stock' => ['quantity' => 3, 'managed' => true],
                'variations' => [['external_id' => '11', 'title' => 'מידה 6', 'price' => ['price' => 990], 'stock' => ['in_stock' => true, 'quantity' => 2]],
                    ['external_id' => '12', 'title' => 'מידה 7', 'price' => ['price' => 990], 'stock' => ['in_stock' => false, 'quantity' => 0]]]],
        ]);
        $chain = $this->product('2', 'שרשרת זהב דקה', '', []);
        $this->inShop(function () use ($ring, $chain, $vocabulary): void {
            foreach ([[FactKind::Type, 'type', 'ring', null], [FactKind::Choice, 'metal', 'yellow_gold', null], [FactKind::Choice, 'stone', 'ruby', null], [FactKind::Choice, 'stone_color', 'red', null]] as $i => [$kind, $key, $value]) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $ring->id, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key,
                    'value_text' => $value, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "r{$i}"]);
            }
            // a reading nobody approved is never on the card
            EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $ring->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Choice, 'key' => 'stone',
                'value_text' => 'sapphire', 'origin' => FactOrigin::Model, 'status' => FactStatus::AwaitingReview, 'input_hash' => 'm1']);
            EnrichmentProductRelation::query()->create(['shop_id' => $this->shop->id, 'product_id' => $ring->id, 'related_product_id' => $chain->id, 'kind' => RelationKind::Complement,
                'source' => 'bought_together', 'score' => 80, 'reasons' => [], 'computed_at' => now()]);
            $policy = CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'page', 'external_id' => 'policy-refund', 'title' => 'מדיניות החזרות',
                'url' => 'https://store.test/policies/refund', 'body' => '<h2>החזרות</h2><p>ניתן להחזיר תוך 14 יום.</p>', 'product_external_ids' => ['1'], 'hash' => 'p1']);
            EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'content_id' => $policy->id, 'kind' => FactKind::Promise, 'key' => 'returns',
                'value_text' => 'החזרה תוך 14 יום', 'quote' => 'ניתן להחזיר תוך 14 יום.', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'pr1']);
        });

        $run = app(BuildCards::class)->handle($this->shop->id);
        $this->assertSame(['products' => 2, 'contents' => 1, 'changed' => 4, 'removed' => 0], $run->output);

        $card = $this->inShop(fn () => app(ReadsCards::class)->product($ring->id));
        $this->assertSame('1', $card['id']);
        $this->assertSame('טבעת', $card['type']);
        $this->assertSame(['מתכת' => 'זהב צהוב', 'אבן' => 'רובי', 'צבע האבן' => 'אדום'], array_intersect_key($card['facts'], array_flip(['מתכת', 'אבן', 'צבע האבן'])), 'labelled in the shop\'s language, approved only');
        $this->assertEquals([990, 1290, true, 3], [$card['price'], $card['regular_price'], $card['on_sale'], $card['stock']]);
        $this->assertEquals([['id' => '11', 'title' => 'מידה 6', 'price' => 990.0, 'in_stock' => true, 'stock' => 2], ['id' => '12', 'title' => 'מידה 7', 'price' => 990.0, 'in_stock' => false, 'stock' => 0]], $card['variants']);
        $this->assertSame(['complements' => [['id' => '2', 'title' => 'שרשרת זהב דקה', 'why' => 'bought_together']]], $card['related']);
        $this->assertStringContainsString('משובצת אבן רובי טבעית', $card['text']);
        $this->assertArrayNotHasKey('highlights', $card, 'nothing said: no empty field to read as a fact');

        $page = $this->inShop(fn () => app(ReadsCards::class)->content(CatalogContent::query()->sole()->id));
        $this->assertSame(['החזרות'], $page['headings']);
        $this->assertSame([['id' => '1', 'title' => 'טבעת רובי בזהב צהוב']], $page['products']);

        $store = $this->inShop(fn () => app(ReadsCards::class)->store());
        $this->assertSame('החזרה תוך 14 יום', $store['policies'][0]['say']);
        $this->assertSame([['title' => 'מדיניות החזרות', 'url' => 'https://store.test/policies/refund']], $store['pages']);

        // Unchanged: nothing written again. A price changed: that card alone.
        $this->assertSame(0, app(BuildCards::class)->handle($this->shop->id)->output['changed']);
        $this->inShop(fn () => CatalogProduct::query()->whereKey($chain->id)->update(['price' => '450.00']));
        $this->assertSame(1, app(BuildCards::class)->handle($this->shop->id)->output['changed']);
    }

    public function test_a_new_product_is_carded_by_its_sync_and_one_met_before_any_build_is_carded_when_read(): void
    {
        $this->buildShop();
        $fresh = $this->product('5', 'מברגה נטענת 18V', 'כוללת 2 סוללות.', []);

        event(new CatalogProductsChanged($this->shop->id, ['5']));
        $this->assertSame(1, $this->inShop(fn () => EnrichmentCard::query()->where('kind', EnrichmentCard::PRODUCT)->where('subject_id', $fresh->id)->count()), 'minutes after the sync, not the night');

        $late = $this->product('6', 'מקדחה רוטטת', '', []);
        $this->assertSame('מקדחה רוטטת', $this->inShop(fn () => app(ReadsCards::class)->product($late->id))['title'], 'read before any build: built now');
    }
}
