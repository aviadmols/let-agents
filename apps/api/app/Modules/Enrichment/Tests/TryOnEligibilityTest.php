<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\TryOnEligibility;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which products could be tried on a person or placed in a room: clothes, jewelry and whole pieces
 * of furniture, decided in code from the vocabulary (ADR 0012). "Not looked at" is never "no".
 */
final class TryOnEligibilityTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_the_vocabulary_names_the_classes_and_nothing_else_passes(): void
    {
        $jewelry = ImportVocabulary::template('jewelry');
        $this->assertSame([], VocabularyDefinition::parse($jewelry)[1], 'the shipped template is valid with its try-on classes and importance');

        $bad = $jewelry;
        $bad['product_types'][0]['tryon'] = 'shoes';
        $this->assertStringContainsString('product_types[0].tryon must be one of', implode(' ', VocabularyDefinition::parse($bad)[1]));

        $bad = $jewelry;
        $bad['tryon'] = true;
        $this->assertStringContainsString('tryon is false or absent', implode(' ', VocabularyDefinition::parse($bad)[1]));

        $bad = $jewelry;
        $bad['attributes'][0]['importance'] = 'vital';
        $this->assertStringContainsString('attributes[0].importance must be one of', implode(' ', VocabularyDefinition::parse($bad)[1]));

        $bad = $jewelry;
        $bad['tryon_exclude_patterns'] = ['(unclosed'];
        $this->assertStringContainsString('tryon_exclude_patterns[0] is not a valid pattern', implode(' ', VocabularyDefinition::parse($bad)[1]));
    }

    public function test_a_vocabulary_that_says_nothing_leaves_a_product_undecided_and_one_that_says_it_decides(): void
    {
        $tools = VocabularyDefinition::fromTrusted(ImportVocabulary::template('power-tools'));
        $this->assertNull(TryOnEligibility::decide($tools, 'impact_driver', 'מברגת אימפקט'), 'power tools never said: undecided, not "no"');
        $this->assertNull(TryOnEligibility::decide(null, 'ring', 'טבעת'));

        $this->assertSame(['class' => 'none', 'source' => 'type'], TryOnEligibility::decide(VocabularyDefinition::fromTrusted(['tryon' => false] + ImportVocabulary::template('power-tools')), 'impact_driver', 'מברגת אימפקט'), 'a branch that says "no" says it for every product');

        $jewelry = VocabularyDefinition::fromTrusted(ImportVocabulary::template('jewelry'));
        $this->assertNull(TryOnEligibility::decide($jewelry, null, 'משהו'), 'no type found: undecided');
        $this->assertSame(['class' => 'ring', 'source' => 'type'], TryOnEligibility::decide($jewelry, 'ring', 'טבעת יהלום'));
        $this->assertSame(['class' => 'necklace', 'source' => 'type'], TryOnEligibility::decide($jewelry, 'pendant', 'תליון לב'), 'a pendant is shown on the neck');
        $this->assertSame(['class' => 'none', 'source' => 'type'], TryOnEligibility::decide($jewelry, 'gift_card', 'כרטיס מתנה'));
        $this->assertSame(['class' => 'none', 'source' => 'type'], TryOnEligibility::decide($jewelry, 'anklet', 'צמיד רגל'), 'an ankle needs another photo: not now');
        $this->assertSame('jewelry', TryOnEligibility::groupOf('ring'));
        $this->assertNull(TryOnEligibility::groupOf('shoes'));
    }

    public function test_only_a_whole_piece_of_furniture_counts_never_its_part_or_cover(): void
    {
        $furniture = VocabularyDefinition::fromTrusted([
            'schema_version' => 1, 'key' => 'furniture', 'name' => ['he' => 'ריהוט', 'en' => 'Furniture'], 'root_category_external_id' => '*',
            'product_types' => [
                ['key' => 'sofa', 'tryon' => 'furniture', 'label' => ['he' => 'ספה', 'en' => 'Sofa']],
                ['key' => 'rug', 'label' => ['he' => 'שטיח', 'en' => 'Rug']],
            ],
            'tryon_exclude_patterns' => ['דגם\s+תצוגה'],
        ]);

        $this->assertSame('furniture', TryOnEligibility::decide($furniture, 'sofa', 'ספה תלת מושבית אפורה')['class']);

        foreach (['רגליים לספה מעץ', 'כרית לספה מדגם נורדי', 'כיסוי לספה תלת מושבית', 'Cover for sofa 3 seats', 'ידית לספה נפתחת', 'דגם תצוגה ספה פינתית'] as $title) {
            $this->assertSame(['class' => 'none', 'source' => 'part'], TryOnEligibility::decide($furniture, 'sofa', $title), $title);
        }

        $this->assertSame(['class' => 'none', 'source' => 'type'], TryOnEligibility::decide($furniture, 'rug', 'שטיח צמר'), 'a type the vocabulary did not mark is "no"');
    }

    public function test_the_scan_marks_every_product_it_can_and_keeps_the_marks_with_the_other_facts(): void
    {
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test');
        $ring = $this->product('1', 'טבעת וינטג\' משובצת גרנט', '', []);
        $card = $this->product('2', 'כרטיס מתנה 500', '', []);
        $unknown = $this->product('3', 'חפץ מסתורי', '', []);

        $run = app(ReadProductsInCode::class)->handle($this->shop->id);

        $classes = $this->inShop(fn () => EnrichmentFact::query()->where('kind', FactKind::TryOn)->where('status', '!=', FactStatus::Superseded)->pluck('value_text', 'product_id')->all());
        $this->assertSame('ring', $classes[$ring->id] ?? null);
        $this->assertSame('none', $classes[$card->id] ?? null, 'known and not eligible is written, so it is not asked again');
        $this->assertArrayNotHasKey($unknown->id, $classes, 'no type found: nothing said either way');
        $this->assertSame(1, $run->output['tryon_yes']);
        $this->assertSame(1, $run->output['tryon_no']);

        // The mark is part of the reading: the same run again changes nothing.
        $again = app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(0, $again->output['changed']);
    }
}
