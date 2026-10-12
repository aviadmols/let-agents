<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Actions\BuildPageBank;
use App\Modules\Widget\Filament\Operator\Pages\ProductPage;
use App\Modules\Widget\Models\WidgetCuration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The store team's say on one page: pins, hides and additions, over code and learning. */
final class CurationTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private CatalogProduct $page;

    private int $events = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        // These sections are built here from the relations computed at night.
        Features::override('enrichment.relations', true, $this->shop->id);
        $this->page = $this->product('10', 'מסור אנכי', 'x', []);

        foreach (['21', '22', '23', '24', '25'] as $i => $id) {
            $this->relation($id, 'complement', 100 - $i);
        }
        $this->relation('31', 'alternative', 50);
        $this->product('90', 'מוצר שהצוות בחר', 'x', []);
    }

    public function test_the_team_pins_hides_and_adds_products_and_the_widget_follows(): void
    {
        $this->assertSame(['21', '22', '23', '24'], $this->ids('complement'));

        // Hide one, pin one that was a spare, add one code never found: pins first, hidden gone.
        $this->decide('complement', '22', WidgetCuration::HIDE);
        $this->decide('complement', '25', WidgetCuration::PIN);
        $this->decide('complement', '90', WidgetCuration::PIN);

        $this->assertSame(['25', '90', '21', '23'], $this->ids('complement'));

        // Learning would drop 21 and 23 after enough opens; a pin never drops.
        $this->inShop(fn () => AnalyticsScore::query()->create([
            'shop_id' => $this->shop->id, 'scope' => AnalyticsScore::SCOPE_PAGE, 'candidate' => 'complement', 'page_type' => 'product', 'page_external_id' => '10',
            'related_external_id' => '', 'exposures' => 100, 'opens' => 60, 'clicks' => 0, 'adds' => 0, 'purchases' => 0, 'value' => 0, 'score' => 0.01, 'computed_at' => now(),
        ]));
        $this->assertSame(['25', '90'], $this->ids('complement'), 'pins survive learning; the never-clicked rest is dropped');

        // A hidden section is gone; a pinned section comes first.
        $this->decide('complement', '', WidgetCuration::HIDE);
        $this->assertSame(['alternatives'], array_column($this->sections(), 'candidate'));

        $this->decide('complement', '', null);
        $this->decide('alternatives', '', WidgetCuration::PIN);
        $this->assertSame(['alternatives', 'complement'], array_column($this->sections(), 'candidate'));

        // A pin into a section code did not build creates it.
        $this->decide('family', '31', WidgetCuration::PIN);
        $this->assertSame(['31'], $this->ids('family'));

        // The explanation for the team: source, score and reasons per product, and the team's decisions.
        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he', explain: true);
        $this->assertSame('test', $bank['explain']['complement']['25']['source']);
        $this->assertSame(['25', '90'], $bank['explain']['complement']['']['pinned']);
        $this->assertSame(['22'], $bank['explain']['complement']['']['hidden']);
        $this->assertArrayNotHasKey('explain', app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he'), 'never on the storefront');
    }

    public function test_the_operator_page_shows_why_and_saves_decisions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $this->inShop(fn () => AnalyticsPopularity::query()->create([
            'shop_id' => $this->shop->id, 'product_external_id' => '10', 'adds' => 9, 'orders' => 2, 'units' => 3,
            'score' => 13, 'rank' => 1, 'popular' => true, 'window_days' => 30, 'computed_at' => now(),
        ]));

        app(TenantContext::class)->set($this->shop->id);

        Livewire::test(ProductPage::class, ['shop' => $this->shop->id, 'type' => 'product', 'id' => '10'])
            ->assertSee('מסור אנכי')
            ->assertSee('מתאים לקנות יחד')
            ->assertSee('ציון קשר 100')
            ->assertSee('נוסף לסל 9 פעמים והופיע ב־2 הזמנות (3 יחידות) ב־30 הימים האחרונים · מקום 1 בחנות')
            ->assertSee('מסומן כפופולרי')
            ->call('hide', 'complement', '21')
            ->call('startAdding', 'complement')
            ->set('addSearch', 'הצוות')
            ->assertSee('מוצר שהצוות בחר')
            ->call('add', '90')
            ->assertSee('מוצמד');

        $this->assertSame(['22', '23', '24', '90'], collect($this->ids('complement'))->sort()->values()->all());
        $this->assertSame(2, $this->inShop(fn () => WidgetCuration::query()->count()));
    }

    public function test_the_page_lists_every_part_of_the_widget_with_its_clicks_and_the_questions_asked(): void
    {
        // Shoppers saw the complements four times, opened them twice, clicked 21 and bought it.
        $this->event('exposure', 'complement', times: 4);
        $this->event('open', 'complement', times: 2);
        $this->event('click', 'complement', item: '21');
        $this->event('add_to_cart', 'complement', item: '21', extra: ['source' => 'widget', 'result' => 'added']);
        $this->event('click', 'contact');
        // The team's own preview visits are not what shoppers did.
        $this->event('click', 'complement', item: '22', times: 9, extra: ['preview' => true]);

        $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->page->id, 'question_key' => hash('sha256', 'q'),
            'question' => 'אפשר לנסר איתו מתכת?', 'answer' => 'כן, עם להב מתאים.', 'outcome' => AssistantAnswer::ANSWERED,
            'source' => 'general', 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 2, 'asked_count' => 3,
            'last_asked_at' => now(),
        ]));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        app(TenantContext::class)->set($this->shop->id);

        $page = Livewire::test(ProductPage::class, ['shop' => $this->shop->id, 'type' => 'product', 'id' => '10']);

        $counts = $page->instance()->page()['activity'];
        $this->assertEquals(['exposures' => 4, 'opens' => 2, 'clicks' => 1, 'adds' => 1], $counts['by_candidate']['complement'], 'preview clicks left out');
        $this->assertEquals(['clicks' => 1, 'adds' => 1], $counts['by_item']['complement']['21']);
        $this->assertSame(['clicks' => 1], $counts['by_candidate']['contact']);

        // Every part of the widget is listed, whether it is shown or not.
        $parts = collect($page->instance()->page()['extras'])->keyBy('key');
        $this->assertSame(['quote', 'popularity', 'contact', 'ask', 'recent', 'signup', 'compare'], $parts->keys()->all());
        $this->assertFalse($parts['contact']['on'], 'the WhatsApp strip is off for this shop');
        $this->assertTrue($parts['recent']['on']);

        $page->assertSee('כל מה שמוצג בעמוד')
            ->assertSee('רצועת הוואטסאפ')
            ->assertSee('תיבת השאלות')
            ->assertSee('מוצרים שהגולש ראה')
            ->assertSee('אפשר לנסר איתו מתכת?')
            ->assertSee('נשאלה 3 פעמים')
            ->assertSee('ידע כללי');
    }

    /** @param array<string, mixed> $extra */
    private function event(string $type, string $candidate, ?string $item = null, int $times = 1, array $extra = []): void
    {
        $this->inShop(function () use ($type, $candidate, $item, $times, $extra): void {
            foreach (range(1, $times) as $i) {
                AnalyticsEvent::query()->create(array_replace([
                    'shop_id' => $this->shop->id, 'event_id' => 'e'.++$this->events, 'type' => $type,
                    'page_type' => 'product', 'page_path' => '/product/10', 'product_external_id' => '10',
                    'item_external_id' => $item, 'candidate_id' => $candidate, 'visitor_hash' => 'v'.$i,
                    'session_id' => 's1', 'preview' => false, 'occurred_at' => now()->subMinutes($i),
                ], $extra));
            }
        });
    }

    /** @return list<string> */
    private function ids(string $candidate): array
    {
        $section = collect($this->sections())->firstWhere('candidate', $candidate);

        return $section === null ? [] : array_column($section['products'], 'id');
    }

    /** @return list<array<string, mixed>> */
    private function sections(): array
    {
        return app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he')['sections'];
    }

    private function decide(string $candidate, string $item, ?string $action): void
    {
        $this->inShop(function () use ($candidate, $item, $action): void {
            $keys = ['shop_id' => $this->shop->id, 'page_type' => 'product', 'page_external_id' => '10', 'candidate' => $candidate, 'item_external_id' => $item];
            $action === null
                ? WidgetCuration::query()->where($keys)->delete()
                : WidgetCuration::query()->updateOrCreate($keys, ['action' => $action]);
        });
    }

    private function relation(string $externalId, string $kind, int $score): void
    {
        $related = $this->product($externalId, 'מוצר '.$externalId, 'x', []);

        $this->inShop(fn () => EnrichmentProductRelation::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->page->id, 'related_product_id' => $related->id,
            'kind' => $kind, 'source' => 'test', 'score' => $score, 'reasons' => [], 'computed_at' => now(),
        ]));
    }
}
