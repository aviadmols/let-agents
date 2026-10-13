<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Pages\ProductGaps;
use App\Modules\Enrichment\Jobs\RereadProductsJob;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\FactGaps;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A detail most products of a kind have and a few do not, as Gueta Avigdor's Makita DTD153RME
 * taught: its text says "סוללות כלולות: 2× … מטען כלול: כן", no pattern fit, and it was the one
 * impact driver that did not say what is in its box.
 */
final class ProductGapsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_detail_most_of_a_kind_have_is_a_gap_for_the_rest_its_likely_misses_first(): void
    {
        $this->buildShop();
        $drills = [];
        foreach (['1' => ['מברגת אימפקט DTD152 גוף בלבד', ''], '2' => ['מברגת אימפקט DTD153 עם 2 סוללות ומטען', ''], '3' => ['מברגת אימפקט DTD154 גוף', ''],
            '4' => ['מברגת אימפקט DTD155 כולל סוללות ומטען', ''], '5' => ['מברגת אימפקט DTD156 גוף', ''],
            '6' => ['מברגה מקיטה אימפקט DTD153RME', 'סוללות כלולות: 2× ליתיום-יון 18V. מטען כלול: כן'], '7' => ['מברגת אימפקט DTD157', 'מברגה חזקה.']] as $id => [$title, $text]) {
            $drills[$id] = $this->product($id, $title, $text, []);
        }
        $this->product('8', 'פלטת לאמי אורן', '', []);
        $vocabulary = $this->powerToolsVocabulary();

        $this->inShop(function () use ($drills, $vocabulary): void {
            $n = 0;
            $fact = fn (string $productId, FactKind $kind, string $key, string $value, FactStatus $status = FactStatus::Approved) => EnrichmentFact::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => $productId, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key, 'value_text' => $value,
                'origin' => FactOrigin::cases()[0], 'status' => $status, 'input_hash' => 'h'.(++$n),
            ]);
            foreach ($drills as $drill) {
                $fact($drill->id, FactKind::Type, 'type', 'impact_driver');
                $fact($drill->id, FactKind::Choice, 'brand', 'makita');
            }
            foreach (['1' => 'body_only', '2' => 'with_batteries', '3' => 'body_only', '4' => 'with_batteries', '5' => 'body_only'] as $id => $kit) {
                $fact($drills[$id]->id, FactKind::Choice, 'kit', $kit);
            }
            // One waits for review: there, not yet approved.
            $fact($drills['7']->id, FactKind::Choice, 'kit', 'body_only', FactStatus::AwaitingReview);
            // A detail only one has is no gap for the others.
            $fact($drills['1']->id, FactKind::Choice, 'color', 'blue');
        });

        $gaps = $this->inShop(fn (): array => FactGaps::of());

        $this->assertCount(1, $gaps, 'the brand all have; the color one has; the kit most have');
        $this->assertSame(['impact_driver', 'kit', 5, 7], [$gaps[0]['type'], $gaps[0]['attribute'], $gaps[0]['have'], $gaps[0]['of']]);
        $this->assertSame(['6', '7'], array_column($gaps[0]['missing'], 'external_id'), 'the one whose text speaks of it first');
        $this->assertNotSame([], $gaps[0]['missing'][0]['hints'], 'סוללות, מטען');
        $this->assertSame('גוף בלבד', $gaps[0]['missing'][1]['waiting'], 'as the vocabulary says it');

        // The operator sends them to be read again.
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Livewire::test(ProductGaps::class)->assertSee('DTD153RME')->assertSee($gaps[0]['attribute_label'])->call('reread', 'impact_driver', 'kit');
        Queue::assertPushed(RereadProductsJob::class, fn (RereadProductsJob $job): bool => $job->vocabularyId === $vocabulary->id && $job->productIds === [$drills['6']->id, $drills['7']->id]);
    }
}
