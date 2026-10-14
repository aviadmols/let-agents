<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the shop's team says of a detail of a product: a value, or "does not apply". It is a fact
 * like the others, and it stands: no later reading by code or a model replaces it.
 */
final class TeamValueTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private EnrichmentVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        $this->vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test')['vocabulary'];
    }

    public function test_a_value_the_team_gives_closes_the_gap_and_a_wrong_one_is_refused_with_a_reason(): void
    {
        $ring = $this->product('1', 'טבעת סוליטר', '', []);
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $apply = app(ApplyTeamValue::class);

        $this->assertSame(['metal'], array_column($this->gaps(), 'attribute'), 'nothing says what the ring is made of');

        $this->assertSame(['ok' => false, 'problem' => 'not_a_choice'], $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'metal', 'plastic')));
        $this->assertSame(['ok' => false, 'problem' => 'unknown_attribute'], $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'shine', 'high')));
        $this->assertSame(['ok' => false, 'problem' => 'unsupported'], $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'adjustable', 'yes')), 'a yes or no is not asked for');

        $this->assertSame(['ok' => true, 'problem' => null], $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'metal', ['white_gold', 'yellow_gold'])), 'metal is a choice that may hold several');

        $facts = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ring->id)->where('key', 'metal')->where('status', FactStatus::Approved)->orderBy('value_text')->get());
        $this->assertSame(['white_gold', 'yellow_gold'], $facts->pluck('value_text')->all());
        $this->assertSame([FactOrigin::Person, FactOrigin::Person], $facts->pluck('origin')->all());
        $this->assertSame('team', $facts[0]->evidence['source']);
        $this->assertSame([], $this->gaps());
    }

    public function test_a_number_is_read_with_a_comma_checked_in_its_range_and_stored_in_the_dimensions_unit(): void
    {
        $ring = $this->product('1', 'טבעת סוליטר', '', []);
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $apply = app(ApplyTeamValue::class);

        $this->assertSame('not_a_number', $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'carat_weight', 'big'))['problem']);
        $this->assertSame('out_of_range', $this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'carat_weight', 9000))['problem']);
        $this->assertTrue($this->inShop(fn () => $apply->set($ring->id, $this->vocabulary->id, 'carat_weight', '0,5'))['ok']);

        $fact = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ring->id)->where('key', 'carat_weight')->where('status', FactStatus::Approved)->first());
        $this->assertSame(0.5, (float) $fact->value_number);
        $this->assertSame('ct', $fact->unit);
        $this->assertSame(0.5, ApplyTeamValue::number('0.5'));
        $this->assertNull(ApplyTeamValue::number('abc'));
    }

    public function test_does_not_apply_is_not_a_gap_and_not_a_fact_about_the_product_and_can_be_taken_back(): void
    {
        $ring = $this->product('1', 'טבעת סוליטר', '', []);
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $apply = app(ApplyTeamValue::class);

        $this->inShop(fn () => $apply->notApplicable($ring->id, $this->vocabulary->id, 'metal'));

        $this->assertSame([], $this->gaps());
        $this->assertSame(0, $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ring->id)->where('key', 'metal')->where('status', FactStatus::Approved)->count()), 'nothing a shopper would be shown');

        $this->inShop(fn () => $apply->clear($ring->id, 'metal'));
        $this->assertSame(['metal'], array_column($this->gaps(), 'attribute'));
    }

    public function test_a_later_reading_by_code_or_a_model_does_not_replace_what_the_team_said(): void
    {
        $ring = $this->product('1', 'טבעת זהב צהוב', '', []);
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $approved = fn (): array => $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ring->id)->where('key', 'metal')->where('status', FactStatus::Approved)->pluck('value_text')->all());
        $this->assertSame(['yellow_gold'], $approved(), 'code read it from the title');

        $this->inShop(fn () => app(ApplyTeamValue::class)->set($ring->id, $this->vocabulary->id, 'metal', 'white_gold'));
        $this->assertSame(['white_gold'], $approved(), 'the team replaces what code read');

        // The product changes, so code reads it again: the team's word stays.
        $this->inShop(fn () => $ring->update(['title' => 'טבעת זהב צהוב 14K חדשה', 'hash' => 'new']));
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(['white_gold'], $approved(), 'code does not write that detail again');
        $this->assertTrue($this->inShop(fn () => FactWriter::teamSaid('product_id', $ring->id, 'metal')));
        $this->assertFalse($this->inShop(fn () => FactWriter::teamSaid('product_id', $ring->id, 'gold_karat')));
    }

    /** @return list<array<string, mixed>> */
    private function gaps(): array
    {
        return $this->inShop(fn (): array => RequiredGaps::of());
    }
}
