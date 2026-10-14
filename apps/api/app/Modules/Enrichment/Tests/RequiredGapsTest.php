<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Pages\ProductGaps;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a shopper must know before buying and the product does not say: the branch's vocabulary names
 * it (importance), so a product lacks it even when no other product of its kind states it either.
 * The case that taught it: "is the battery in the box?" on drills, and the size of a diamond.
 */
final class RequiredGapsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private int $n = 0;

    public function test_a_required_attribute_missing_is_a_gap_even_when_no_product_has_it(): void
    {
        $this->buildShop();
        $vocabulary = $this->vocabularyAsking(['kit' => ['required', ['impact_driver']], 'power_source' => ['recommended', null], 'voltage_v' => ['optional', null]]);
        $drills = [];
        foreach (['1' => 'מברגת אימפקט A', '2' => 'מברגת אימפקט B', '3' => 'מברגת אימפקט C', '4' => 'מברגת אימפקט D'] as $id => $title) {
            $drills[$id] = $this->product($id, $title, '', []);
        }
        $saw = $this->product('5', 'מסור שרשרת', '', []);

        $this->inShop(function () use ($drills, $saw, $vocabulary): void {
            foreach ($drills as $drill) {
                $this->fact($vocabulary, $drill->id, FactKind::Type, 'type', 'impact_driver');
            }
            $this->fact($vocabulary, $saw->id, FactKind::Type, 'type', 'chainsaw');
            $this->fact($vocabulary, $drills['1']->id, FactKind::Choice, 'kit', 'body_only');
            $this->fact($vocabulary, $drills['2']->id, FactKind::Choice, 'kit', 'with_batteries');
            $this->fact($vocabulary, $drills['3']->id, FactKind::Choice, 'kit', 'body_only', FactStatus::AwaitingReview);
        });

        $gaps = $this->inShop(fn (): array => RequiredGaps::of());

        $this->assertCount(1, $gaps, 'only the required attribute, and only on the type it applies to; the saw is not asked about a kit');
        $this->assertSame(['impact_driver', 'kit', 'required', 2, 4], [$gaps[0]['type'], $gaps[0]['attribute'], $gaps[0]['importance'], $gaps[0]['have'], $gaps[0]['of']]);
        $this->assertSame(['3', '4'], array_column($gaps[0]['missing'], 'external_id'), 'the one with a value waiting for review first');
        $this->assertNotNull($gaps[0]['missing'][0]['waiting']);
        $this->assertNull($gaps[0]['missing'][1]['waiting']);
        $this->assertNull($gaps[0]['why'], 'no reason written for this one');

        $withRecommended = $this->inShop(fn (): array => RequiredGaps::of([VocabularyDefinition::REQUIRED, VocabularyDefinition::RECOMMENDED]));
        $this->assertSame([['impact_driver', 'kit'], ['impact_driver', 'power_source'], ['chainsaw', 'power_source']], array_map(fn (array $g): array => [$g['type'], $g['attribute']], $withRecommended),
            'required first; then the recommended one when asked for, on every type it applies to (the saw too: nothing limits it; the kind with more products missing it first)');
    }

    public function test_a_yes_or_no_attribute_is_not_counted_and_a_removed_product_is_not_asked_about(): void
    {
        $this->buildShop();
        $vocabulary = $this->vocabularyAsking(['brushless' => ['required', null]]);
        $drill = $this->product('1', 'מברגת אימפקט A', '', []);
        $gone = $this->product('2', 'מברגת אימפקט ישנה', '', [], ['removed_at' => now()]);
        $this->inShop(function () use ($vocabulary, $drill, $gone): void {
            $this->fact($vocabulary, $drill->id, FactKind::Type, 'type', 'impact_driver');
            $this->fact($vocabulary, $gone->id, FactKind::Type, 'type', 'impact_driver');
        });

        $this->assertSame([], $this->inShop(fn (): array => RequiredGaps::of()), 'code stores only a yes, so a missing yes cannot be told from a no');

        $this->inShop(fn () => EnrichmentVocabulary::query()->whereKey($vocabulary->id)->get()->each(function (EnrichmentVocabulary $row): void {
            $definition = $row->definition()->toArray();
            foreach ($definition['attributes'] as &$attribute) {
                if ($attribute['key'] === 'kit') {
                    $attribute['importance'] = 'required';
                }
            }
            $row->update(['definition' => $definition]);
        }));
        $gaps = $this->inShop(fn (): array => RequiredGaps::of());
        $this->assertSame(['1'], array_column($gaps[0]['missing'], 'external_id'), 'a product the store no longer publishes is left out');
    }

    public function test_the_operator_sees_what_the_branch_asks_for_and_how_many_products_are_marked_for_try_on(): void
    {
        $this->buildShop();
        $vocabulary = $this->vocabularyAsking(['kit' => ['required', ['impact_driver']]]);
        $drill = $this->product('1', 'מברגת אימפקט DTD', '', []);
        $this->product('2', 'פריט שלא נקבע', '', []);
        $this->inShop(function () use ($vocabulary, $drill): void {
            $this->fact($vocabulary, $drill->id, FactKind::Type, 'type', 'impact_driver');
            $this->fact($vocabulary, $drill->id, FactKind::TryOn, 'class', 'none');
        });

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);

        Livewire::test(ProductGaps::class)
            ->assertSeeHtml('data-required-gap="impact_driver.kit"')
            ->assertSee('מברגת אימפקט DTD')
            ->assertSee('0 מוצרים אפשר למדוד, 1 אי אפשר, 1 עדיין לא הוחלט.');
    }

    /**
     * Power tools, with the importance of the named attributes set (and the types one applies to).
     *
     * @param  array<string, array{0: string, 1: list<string>|null}>  $asks
     */
    private function vocabularyAsking(array $asks): EnrichmentVocabulary
    {
        $data = ImportVocabulary::template('power-tools');
        $data['root_category_external_id'] = '*';
        $data['excluded_category_external_ids'] = [];

        foreach ($data['attributes'] as &$attribute) {
            if (isset($asks[$attribute['key']])) {
                [$level, $appliesTo] = $asks[$attribute['key']];
                $attribute['importance'] = $level;
                $appliesTo === null ? null : $attribute['applies_to'] = $appliesTo;
            }
        }

        return app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test')['vocabulary'];
    }

    private function fact(EnrichmentVocabulary $vocabulary, string $productId, FactKind $kind, string $key, string $value, FactStatus $status = FactStatus::Approved): void
    {
        EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $productId, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key, 'value_text' => $value,
            'origin' => FactOrigin::Code, 'status' => $status, 'input_hash' => 'h'.(++$this->n),
        ]);
    }
}
