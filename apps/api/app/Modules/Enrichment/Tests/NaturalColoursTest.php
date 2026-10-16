<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stone that comes in one colour says its colour: a ruby ring is a ring with a red stone, so a
 * search for "טבעת עם אבן אדומה" finds it. Code only, and never over a colour the product states.
 */
final class NaturalColoursTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_stones_own_colour_is_its_colour_unless_the_product_says_another(): void
    {
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        Features::override('enrichment.auto_approve', true, $this->shop->id);
        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test')['vocabulary'];

        $ruby = $this->product('1', 'טבעת וינטאג משובצת אבני רובי, זהב 18K', '', []);
        $carnelian = $this->product('2', "טבעת וינטג' משובצת קרנליון, זהב אדום", '', []);
        $said = $this->product('3', 'טבעת רובי עם אבן ורודה', '', []);
        $sapphire = $this->product('4', 'טבעת ספיר ויהלומים', '', []);
        $byModel = $this->product('5', 'טבעת וינטאג עדינה - מק"ט 4410', '', []);
        // the scan's model read a garnet in the description, and it was approved
        $this->inShop(fn () => EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $byModel->id, 'vocabulary_id' => $vocabulary->id,
            'kind' => FactKind::Choice, 'key' => 'stone', 'value_text' => 'garnet', 'origin' => FactOrigin::Model, 'status' => FactStatus::Approved, 'input_hash' => 'm5']));

        app(ReadProductsInCode::class)->handle($this->shop->id);

        $colours = fn ($product): array => $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $product->id)->where('key', 'stone_color')
            ->where('status', '!=', FactStatus::Superseded)->orderBy('value_text')->pluck('value_text')->all());
        $this->assertSame(['red'], $colours($ruby), 'a ruby is red');
        $this->assertSame(['red'], $colours($carnelian), 'a carnelian is red, and the red gold says nothing of the stone');
        $this->assertSame(['pink'], $colours($said), 'the product says its stone is pink: that stands');
        $this->assertSame([], $colours($sapphire), 'a sapphire comes in many colours');
        $this->assertSame(['red'], $colours($byModel), 'a garnet the model read is red too');

        $implied = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ruby->id)->where('key', 'stone_color')->where('status', FactStatus::Approved)->sole());
        $this->assertSame([FactOrigin::Code, 'code_implied'], [$implied->origin, $implied->status_reason]);
        $this->assertSame(EnrichmentVocabulary::query()->withoutGlobalScopes()->whereKey($vocabulary->id)->value('id'), $implied->vocabulary_id);
    }

    public function test_the_first_scan_keeps_the_colours_its_stones_imply_though_no_title_says_them(): void
    {
        // the sample: rubies and sapphires named, no colour named anywhere
        $coverage = ['product_types' => ['ring' => 1.0], 'attributes' => [
            'stone' => ['share' => 0.4, 'values' => ['ruby' => 0.2, 'sapphire' => 0.2, 'garnet' => 0.0]],
            'stone_color' => ['share' => 0.0, 'values' => ['red' => 0.0, 'blue' => 0.0, 'green' => 0.0]],
        ]];
        $drops = array_column(ProposeSchema::unread($coverage), 'key');

        $this->assertNotContains('stone_color', $drops, 'the colour attribute stays: a ruby says red');
        $this->assertNotContains('stone_color.red', $drops);
        $this->assertContains('stone_color.blue', $drops, 'a sapphire says no colour of its own');
        $this->assertContains('stone_color.green', $drops, 'no emerald in the sample');
        $this->assertContains('stone.garnet', $drops, 'a stone the sample never named still goes');

        // no stone that implies a colour: an unread colour attribute goes, as before
        $coverage['attributes']['stone']['values'] = ['sapphire' => 0.4];
        $this->assertContains('stone_color', array_column(ProposeSchema::unread($coverage), 'key'));
    }
}
