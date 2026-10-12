<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Support\FactSheet;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The facts-only writer's sheet (answer_site.v5): what code holds about each product and the
 * store, each with a ref and an owner; and the gate that holds a reply to them. Hard on refs and
 * owners, not on words.
 */
final class FactSheetTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private array $prepared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $drill = $this->product('1', 'מברגה נטענת Makita DDF485', 'תיאור חופשי שאסור שיגיע לכותב: עמידה במים ומתאימה לכל עבודה.', [], ['in_stock' => true, 'price' => '890.00', 'sku' => 'DDF485']);
        $this->product('2', 'מברגה רוטטת Einhell', 'עוד תיאור חופשי.', [], ['in_stock' => true, 'price' => '240.00']);
        $this->inShop(function () use ($drill): void {
            foreach ([['spec', 'voltage', null, 18, 'V'], ['choice', 'power_source', 'cordless', null, null], ['highlight', 'battery', 'מגיעה עם שתי סוללות 18V', null, null]] as $i => [$kind, $key, $text, $number, $unit]) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $drill->id, 'kind' => FactKind::from($kind), 'key' => $key,
                    'value_text' => $text, 'value_number' => $number, 'unit' => $unit, 'origin' => FactOrigin::cases()[0], 'status' => FactStatus::Approved, 'input_hash' => "h{$i}"]);
            }
            EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $drill->id, 'kind' => FactKind::Highlight, 'key' => 'water',
                'value_text' => 'עמידה במים', 'origin' => FactOrigin::cases()[0], 'status' => FactStatus::AwaitingReview, 'input_hash' => 'h9']);
        });

        $this->prepared = [
            'type' => 'superlative',
            'productsByRef' => [
                'p1' => ['external_id' => '1', 'title' => 'מברגה נטענת Makita DDF485', 'about' => 'תיאור חופשי שאסור שיגיע לכותב: עמידה במים ומתאימה לכל עבודה.', 'category' => 'כלי עבודה > מברגות', 'in_stock' => true, 'on_sale' => false, 'price' => 890.0, 'sku' => 'DDF485', 'attributes' => [], 'options_in_stock' => []],
                'p2' => ['external_id' => '2', 'title' => 'מברגה רוטטת Einhell', 'about' => 'עוד תיאור חופשי.', 'category' => 'כלי עבודה > מברגות', 'in_stock' => true, 'on_sale' => true, 'price' => 240.0, 'sku' => '', 'attributes' => [], 'options_in_stock' => []],
            ],
            'byRef' => [
                's1' => ['title' => 'מידע שמופיע בכל דפי המוצר באתר', 'text' => "- משלוח מהיר לכל הארץ\n- אחריות 12 חודשים", 'kind' => 'knowledge'],
                's2' => ['title' => 'מדריך: איך לבחור מברגה', 'text' => 'מברגה טובה עמידה במים.', 'kind' => 'meaning'],
            ],
            'analysis' => ['asks' => 'cheapest', 'in_stock_cheapest_first' => ['p2', 'p1'], 'out_of_stock' => [], 'on_sale' => ['p2'], 'brands' => []],
        ];
    }

    public function test_the_sheet_holds_facts_with_refs_and_no_free_text(): void
    {
        $sheet = $this->inShop(fn () => FactSheet::build($this->prepared, 'מה המברגה הכי זולה?', 'he'));
        $input = FactSheet::input('מה המברגה הכי זולה?', $sheet);
        $p1 = collect($sheet['products'])->firstWhere('ref', 'p1');
        $says = array_column($p1['facts'], 'say', 'ref');

        $this->assertContains('מגיעה עם שתי סוללות 18V', $says, 'an approved highlight');
        $this->assertContains('מק״ט: DDF485', $says, 'the product\'s own field');
        $this->assertSame('במלאי', $says['p1.stock']);
        $this->assertSame('הזול ביותר שבמלאי מבין המוצרים שנמצאו', collect($sheet['products'])->firstWhere('ref', 'p2')['facts'][array_search('p2.rank', array_column(collect($sheet['products'])->firstWhere('ref', 'p2')['facts'], 'ref'), true)]['say']);
        $this->assertStringNotContainsString('תיאור חופשי', $input, 'no description reaches the writer');
        $this->assertStringNotContainsString('עמידה במים', $input, 'neither a guide\'s passage nor a fact not yet approved');
        $this->assertSame(['משלוח מהיר לכל הארץ', 'אחריות 12 חודשים'], array_column($sheet['store'], 'say'), 'the store\'s statements, one per line');
    }

    public function test_the_gate_is_hard_on_refs_and_owners_and_soft_on_words(): void
    {
        $sheet = $this->inShop(fn () => FactSheet::build($this->prepared, 'מה המברגה הכי זולה?', 'he'));
        $screen = fn (array $data): array => FactSheet::screen($data + ['found' => true, 'picks' => []], $sheet, $this->prepared['productsByRef'], 'מה המברגה הכי זולה?');

        // Natural words, resting on the right facts: shown as written.
        $ok = $screen(['sentences' => [
            ['text' => 'הנה מה שמצאתי:', 'about' => [], 'refs' => []],
            ['text' => 'הזולה ביותר היא המברגה הרוטטת של Einhell, והיא גם במבצע.', 'about' => ['p2'], 'refs' => ['p2.rank', 'p2.sale']],
            ['text' => 'אם חשוב לך כוח, ה-Makita עובדת על 18V ומגיעה עם שתי סוללות.', 'about' => ['p1'], 'refs' => ['p1.f1', 'p1.f3']],
        ], 'picks' => [['ref' => 'p2', 'why' => 'הזולה ביותר שבמלאי', 'refs' => ['p2.rank']]]]);
        $this->assertNull($ok['refusal']);
        $this->assertSame(3, $ok['sentences']);
        $this->assertSame(['2'], array_column($ok['picks'], 'external_id'));

        // One product's fact told of another: refused in code, whatever the words.
        $this->assertSame('wrong_owner', $screen(['sentences' => [['text' => 'ה-Einhell עובדת על 18V.', 'about' => ['p2'], 'refs' => ['p1.f1']]]])['refusal']);
        // A fact that does not exist.
        $this->assertSame('unknown_ref', $screen(['sentences' => [['text' => 'היא עמידה במים.', 'about' => ['p1'], 'refs' => ['p1.f9']]]])['refusal']);
        // A number none of its facts holds.
        $this->assertSame('number_not_in_facts', $screen(['sentences' => [['text' => 'הסוללה מחזיקה 3 שעות.', 'about' => ['p1'], 'refs' => ['p1.f3']]]])['refusal']);
        // A claim with no fact under it.
        $this->assertSame('no_refs', $screen(['sentences' => [['text' => 'המברגה של Makita היא הטובה ביותר לעבודות בית ולגינה.', 'about' => ['p1'], 'refs' => []]]])['refusal']);
        // A store fact may be told in any sentence: it is about the store.
        $this->assertNull($screen(['sentences' => [['text' => 'ה-Makita במלאי, ויש אחריות של 12 חודשים.', 'about' => ['p1', 'store'], 'refs' => ['p1.stock', 'k2']]]])['refusal']);

        // A pick whose why rests on another product's fact is dropped, the rest stands.
        $picks = $screen(['sentences' => [['text' => 'הנה שתי אפשרויות.', 'about' => [], 'refs' => []]], 'picks' => [
            ['ref' => 'p1', 'why' => 'הזולה ביותר', 'refs' => ['p2.rank']],
            ['ref' => 'p2', 'why' => 'הזולה ביותר שבמלאי', 'refs' => ['p2.rank']],
        ]])['picks'];
        $this->assertSame(['p2'], array_column($picks, 'ref'));
    }
}
