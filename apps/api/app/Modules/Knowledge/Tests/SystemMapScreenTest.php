<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Analytics\Models\AnalyticsOrderImport;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Knowledge\Filament\Operator\Pages\SystemMap;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\MatchProducts;
use App\Modules\Retrieval\Models\RetrievalMatchRequest;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The map draws the shop's machinery from the tables: the index, the matching, and one product's
 * page stage by stage — in both languages, and only inside a shop.
 */
final class SystemMapScreenTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    private object $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה עם סוללה ליתיום, לקידוח בעץ ובמתכת.');
        $this->product('11', 'מקדחה נטענת 12V', 'מקדחה נטענת קלה עם סוללה ליתיום, לקידוח בעץ ובמתכת.');
        $this->product('20', 'סט מקדחים לבטון', 'סט מקדחים מוקשים לקידוח בבטון.');
        $this->article('500', 'קידוח בבטון', 'מקדחה ומקדחים לבטון.', ['10', '20']);
        foreach (range(1, 3) as $ignored) {
            $this->order(['10', '20'], 'history', '-5 months');
        }
        $this->inShop(fn () => AnalyticsOrderImport::query()->create([
            'shop_id' => $this->shop->id, 'expected' => 3, 'received' => 3, 'stored' => 3,
            'oldest_ordered_at' => now()->subMonths(5), 'started_at' => now(), 'finished_at' => now(),
        ]));

        // The model takes the bits as a complement and the lighter drill as an alternative.
        $this->model = new class implements ChatModel
        {
            public int $calls = 0;

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;
                $refs = collect(json_decode($user, true)['candidates'])->pluck('ref', 'title');

                return new ModelReply([
                    'complements' => array_values(array_filter([isset($refs['סט מקדחים לבטון']) ? ['ref' => $refs['סט מקדחים לבטון'], 'why' => 'נקנים יחד'] : null])),
                    'alternatives' => array_values(array_filter([isset($refs['מקדחה נטענת 12V']) ? ['ref' => $refs['מקדחה נטענת 12V'], 'why' => 'קלה יותר'] : null])),
                ], 800, 60);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);

        app(BuildIndex::class)->handle($this->shop->id);
        app(MatchProducts::class)->handle($this->shop->id);
        app(ComputeProductRelations::class)->handle($this->shop->id);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_every_tab_draws_from_the_tables_in_both_languages(): void
    {
        $drill = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '10')->value('id'));

        foreach (['he' => ['בניית המאגרים', 'מה ההזמנות אומרות', 'בחירת המודל', 'מה הצוות החליט'], 'en' => ['Building the stores', 'What the orders say', 'The model\'s choice', 'What the team decided']] as $locale => $words) {
            $this->withHeader('Accept-Language', $locale)->get('/operator/system-map?shop='.$this->shop->id)
                ->assertOk()->assertSee($words[0])->assertSee($words[1])->assertSee('text-embedding-3-small');

            $this->withHeader('Accept-Language', $locale)->get('/operator/system-map?shop='.$this->shop->id.'&tab=matching&product='.$drill)
                ->assertOk()->assertSee($words[2])->assertSee('סט מקדחים לבטון')->assertSee('נקנים יחד');

            $this->withHeader('Accept-Language', $locale)->get('/operator/system-map?shop='.$this->shop->id.'&tab=page&product='.$drill)
                ->assertOk()->assertSee($words[3])->assertSee('סט מקדחים לבטון');
        }
    }

    public function test_the_numbers_are_the_shops_own(): void
    {
        app(TenantContext::class)->set($this->shop->id);
        $page = Livewire::test(SystemMap::class)->instance();

        $index = $page->indexMap();
        $this->assertSame(3, $index['store']['products']);
        $this->assertSame(3, $index['store']['orders_history']);
        $this->assertSame(0, $index['pending']);
        $this->assertSame(2, $index['sources']['purchases']['documents'], 'the drill and the bits sold');
        $this->assertSame('succeeded', $index['last']['status']);

        $matching = $page->matchingMap();
        $this->assertSame(3, $matching['evidence']['orders']);
        $this->assertGreaterThan(0, $matching['accepted']['complement']);
        $this->assertArrayHasKey('ai_match', $matching['relations'], 'the model\'s choices became relations');
        $this->assertSame(0, $matching['requests']['never']);
    }

    public function test_a_product_page_is_traced_stage_by_stage_and_its_matching_can_be_asked_again(): void
    {
        app(TenantContext::class)->set($this->shop->id);
        $drill = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '10')->value('id'));

        $component = Livewire::test(SystemMap::class)->set('search', 'מקדחה 18')->call('show', 'page');
        $component->set('search', '18V');
        $this->assertSame([$drill], array_column($component->instance()->results(), 'id'));

        $component->call('choose', $drill);
        $trace = $component->instance()->pageTrace();

        $this->assertSame(['built', 'allowed', 'learned', 'curated'], array_column($trace['stages'], 'stage'));
        $complement = collect($trace['stages'][0]['sections'])->firstWhere('candidate', 'complement');
        $this->assertSame(['20'], array_column($complement['items'], 'id'));
        $this->assertSame('ai_match', $trace['bank']['explain']['alternatives']['11']['source'] ?? null, 'the lighter drill is there because the model chose it');

        $before = $this->model->calls;
        $component->call('askAgain');
        $this->assertSame($before + 1, $this->model->calls, 'asked again even though nothing changed');
        $this->assertSame(1, $this->inShop(fn () => RetrievalMatchRequest::query()->where('product_id', $drill)->count()));
    }

    public function test_the_map_belongs_to_a_shop(): void
    {
        app(TenantContext::class)->clear();

        $this->assertFalse(SystemMap::canAccess());
        $this->assertFalse(SystemMap::shouldRegisterNavigation());
    }
}
