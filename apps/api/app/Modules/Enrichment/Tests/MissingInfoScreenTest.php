<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Pages\MissingInfo;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Support\MissingInfoCsv;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen where the team fills in what the products do not say: one product, several, a
 * spreadsheet, or "does not apply". The shop's manager sees it with nothing about how the system works.
 */
final class MissingInfoScreenTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private EnrichmentVocabulary $vocabulary;

    /** @var array<string, CatalogProduct> */
    private array $rings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        $this->vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test')['vocabulary'];

        foreach (['1' => 'טבעת סוליטר', '2' => 'טבעת וינטאג', '3' => 'טבעת פשוטה'] as $id => $title) {
            $this->rings[$id] = $this->product($id, $title, '', []);
        }
        app(ReadProductsInCode::class)->handle($this->shop->id);
    }

    public function test_the_team_fills_one_product_several_at_once_or_marks_it_not_applying(): void
    {
        $this->operator();
        $group = 'ring|metal';

        $page = Livewire::test(MissingInfo::class)
            ->assertSeeHtml('data-missing-summary')
            ->assertSee('ב־3 מוצרים חסר פרט')
            ->call('toggleGroup', $group)
            ->assertSeeHtml('data-missing-product="1"');

        // One product, by its own value.
        $page->set('entries.'.$this->rings['1']->id, 'white_gold')->call('saveOne', $this->rings['1']->id);
        $this->assertSame(['white_gold'], $this->approved('1', 'metal'));

        // A wrong value says why and saves nothing.
        $page->set('entries.'.$this->rings['2']->id, 'plastic')->call('saveOne', $this->rings['2']->id);
        $this->assertSame([], $this->approved('2', 'metal'));

        // The rest at once: one ticked gets a value, the other is not applicable.
        $page->set('picked', [$this->rings['2']->id])->set('bulkValue', 'yellow_gold')->call('saveMany');
        $this->assertSame(['yellow_gold'], $this->approved('2', 'metal'));
        $page->call('markNotApplicable', $this->rings['3']->id);

        $this->assertSame([], $this->inShop(fn (): array => RequiredGaps::of()), 'nothing that is needed is missing now');
        $page->assertDontSeeHtml('data-missing-group="ring|metal"')->assertSeeHtml('data-missing-group="ring|carat_weight"');
        $this->assertSame(FactStatus::NotApplicable, $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->rings['3']->id)->where('key', 'metal')->where('status', FactStatus::NotApplicable)->first())->status);
    }

    public function test_a_spreadsheet_is_exported_filled_in_and_loaded_back_by_name_or_key(): void
    {
        $csv = $this->inShop(fn (): string => MissingInfoCsv::export(RequiredGaps::of()));

        $this->assertStringStartsWith("\xEF\xBB\xBF".'product_id,title,type,attribute_key,attribute,importance,value', $csv);
        $this->assertSame(4, count(array_filter(explode("\n", trim($csv)))), 'a header and a row for each of the three rings');

        $filled = "product_id,title,type,attribute_key,attribute,importance,value\n"
            ."1,טבעת סוליטר,טבעת,metal,מתכת,required,זהב לבן\n"      // a name
            ."2,טבעת וינטאג,טבעת,metal,מתכת,required,yellow_gold\n"   // a key
            ."3,טבעת פשוטה,טבעת,metal,מתכת,required,-\n"             // does not apply
            ."9,לא קיים,טבעת,metal,מתכת,required,זהב לבן\n"            // no such product
            ."1,טבעת סוליטר,טבעת,carat_weight,קראט,recommended,אין\n"; // not a number

        $result = $this->inShop(fn (): array => MissingInfoCsv::import($filled, app(ApplyTeamValue::class)));

        $this->assertSame(2, $result['applied']);
        $this->assertSame(1, $result['not_applicable']);
        $this->assertSame([[5, 'unknown_product'], [6, 'not_a_number']], array_map(fn (array $p): array => [$p['line'], $p['problem']], $result['problems']));
        $this->assertSame(['white_gold'], $this->approved('1', 'metal'));
        $this->assertSame(['yellow_gold'], $this->approved('2', 'metal'));

        $this->assertSame('bad_header', $this->inShop(fn (): array => MissingInfoCsv::import("a,b\n1,2", app(ApplyTeamValue::class)))['problems'][0]['problem']);
    }

    public function test_the_shop_manager_sees_their_own_missing_details_in_their_own_words_and_nothing_of_the_machinery(): void
    {
        $manager = User::factory()->create();
        $manager->attachShop($this->shop, ShopRole::Owner);

        $response = $this->actingAs($manager)->withHeader('Accept-Language', 'he')->get("/merchant/{$this->shop->slug}/enrichment/missing-info");

        $response->assertOk()->assertSee('פרטים חסרים על מוצרים')->assertSee('ב־3 מוצרים חסר פרט');
        $this->assertDoesNotMatchRegularExpression('/claude|gpt|openai|anthropic|מודל|טוקנ|\$\d/iu', strip_tags($response->getContent()), 'a shop manager is told what is missing, not how it is found');

        $other = User::factory()->create();
        $this->actingAs($other)->get("/merchant/{$this->shop->slug}/enrichment/missing-info")->assertForbidden();
    }

    private function operator(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
    }

    /** @return list<string> */
    private function approved(string $external, string $key): array
    {
        return $this->inShop(fn (): array => EnrichmentFact::query()->where('product_id', $this->rings[$external]->id)->where('key', $key)->where('status', FactStatus::Approved)->orderBy('value_text')->pluck('value_text')->all());
    }
}
