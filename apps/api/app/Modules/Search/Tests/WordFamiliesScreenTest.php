<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Filament\Merchant\Pages\WordFamilies;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchWordFamily;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\StoreFamilies;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The word families screen: the families found in the store's words wait for the team (approved, fixed, called
 * wrong), the team's own stay as they are and win over the written ones, and the words shoppers searched with no
 * family are joined to one or set aside. The store's search reads what the team decided.
 */
final class WordFamiliesScreenTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);

        foreach (['1' => 'חולצה משובצת', '2' => 'מכנסיים במשבצות', '3' => 'חולצה עם כפתורים', '4' => 'ג\'קט עם כפתורים', '5' => 'סווטשירט שחור'] as $id => $title) {
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [], 'price' => 100]);
        }
        SearchWordFamily::query()->create(['shop_id' => $this->shop->id, 'words_hash' => 'x', 'prompt_version' => 3,
            'families' => [['משובצת', 'משבצות', 'משובץ'], ['שירט', 'שורט'], ['סווטשירט', 'סווטשירטים']]]);
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_the_team_approves_fixes_and_calls_wrong_and_the_search_reads_it(): void
    {
        $checks = 'w-'.StoreFamilies::key(['משובצת', 'משבצות', 'משובץ']);
        $shirts = 'w-'.StoreFamilies::key(['שירט', 'שורט']);

        Livewire::test(WordFamilies::class)
            ->assertSee('משפחות מילים')
            ->assertSee('מחכות לאישורכם')
            ->assertSee('משבצות')
            ->call('dropWord', $checks, 'משובץ')
            ->call('reject', $shirts)
            ->assertDontSee('שורט');

        $approved = StoreFamilies::approved();
        $this->assertSame([['משובצת', 'משבצות']], array_column($approved, 'words'), 'a word out: the family is the team\'s from then on');
        $this->assertSame([['סווטשירט', 'סווטשירטים']], StoreFamilies::waiting(), 'the team\'s words and the wrong family out of the written ones');

        Livewire::test(WordFamilies::class)
            ->call('show', 'approved')
            ->assertSee('משבצות')
            ->set('adding.a-'.$approved[0]['id'], 'במשבצות, משובצים')
            ->call('addWord', 'a-'.$approved[0]['id']);
        $this->assertSame([['משובצת', 'משבצות', 'במשבצות', 'משובצים']], array_column(StoreFamilies::approved(), 'words'));

        // the written families come again tomorrow, the same: what the team decided still stands
        SearchWordFamily::query()->sole()->update(['families' => [['משובצת', 'משבצות', 'משובץ'], ['שירט', 'שורט', 'טישירט']]]);
        $this->assertSame([['שורט', 'טישירט']], StoreFamilies::waiting(), '"שירט", "שורט" never one family again; what is new waits for the team');

        $index = json_decode((string) SearchIndex::query()->sole()->items, true);
        $this->assertContains(['משובצת', 'משבצות', 'במשבצות', 'משובצימ'], $index['families'], 'the store\'s search reads the team\'s family');
        $this->assertNotContains(['שירט', 'שורט'], $index['families']);
    }

    public function test_a_searched_word_with_no_family_is_joined_paired_or_hidden(): void
    {
        foreach (['כפתור' => 2, 'כפתור לבן' => 0, 'פנטזיה' => 0, 'פנטזיה' => 0, 'שחור' => 0, 'משבצות' => 4, 'רוכסן' => 0] as $query => $results) {
            SearchLog::query()->create(['shop_id' => $this->shop->id, 'query' => $query, 'results' => $results, 'created_at' => now()->subDays(2)]);
        }
        SearchLog::query()->create(['shop_id' => $this->shop->id, 'query' => 'ישן מאוד', 'results' => 0, 'created_at' => now()->subDays(90)]);

        $screen = Livewire::test(WordFamilies::class)->call('show', 'open')
            ->assertSee('חיפשו ואין משפחה')
            ->assertSee('כפתור')
            ->assertSee('2 חיפושים')
            ->assertSee('פנטזיה')
            ->assertSee('+ כפתורים')
            ->assertDontSee('ישן מאוד');
        $open = array_column($screen->instance()->openRows(), 'word');
        $this->assertNotContains('שחור', $open, 'a colour is no family\'s');
        $this->assertNotContains('משבצות', $open, 'already in a family');
        $this->assertSame('כפתור', $open[0], 'the most searched first');

        $screen->call('pair', 'כפתור', 'כפתורים')
            ->set('joining.0', 'w-'.StoreFamilies::key(['סווטשירט', 'סווטשירטים']))
            ->call('join', 'רוכסן', 0)
            ->call('hide', 'פנטזיה');

        $this->assertEqualsCanonicalizing([['כפתורים', 'כפתור'], ['סווטשירט', 'סווטשירטים', 'רוכסן']], array_column(StoreFamilies::approved(), 'words'));
        $this->assertSame(1, SearchFamilyChoice::query()->where('status', SearchFamilyChoice::HIDDEN)->count());
        $this->assertSame([], array_column(Livewire::test(WordFamilies::class)->instance()->openRows(), 'word'), 'joined, paired or hidden: none left');
    }

    public function test_a_family_is_made_by_hand_and_a_word_is_in_one_of_the_teams_families(): void
    {
        Livewire::test(WordFamilies::class)
            ->set('newFamily', 'קפוצון, hoodie')
            ->call('create')
            ->set('newFamily', 'סווטשירט עם כובע, קפוצון')
            ->call('create')
            ->set('newFamily', 'לבד')
            ->call('create')
            ->assertNotified();

        $this->assertSame([['סווטשירט עם כובע', 'קפוצון']], array_column(StoreFamilies::approved(), 'words'), '"קפוצון" moved, and "hoodie" alone is no family');
    }
}
