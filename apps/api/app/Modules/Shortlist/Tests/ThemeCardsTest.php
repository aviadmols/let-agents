<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Models\Run;
use App\Modules\Shortlist\Actions\FindThemeCards;
use App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist;
use App\Modules\Shortlist\Support\ThemeCards;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where a theme draws its product cards, found on the store's own category page: by code from
 * the links to its products, and by a model only when code finds none, its answer checked by code.
 * As on Gueta Avigdor, whose categories live under /products/ and products under /product/.
 */
final class ThemeCardsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PATHS = ['https://shop.test/product/board-1/', 'https://shop.test/product/board-2/', 'https://shop.test/product/board-3/', 'https://shop.test/product/board-4/'];

    /** @var list<string> */
    public array $replies = [];

    public int $asked = 0;

    private string $page = '';

    private int $status = 200;

    public function test_code_finds_the_cards_from_the_links_to_the_stores_products(): void
    {
        $found = ThemeCards::find($this->wooPage(), self::PATHS);

        $this->assertSame(['selector' => '.product-card', 'cards' => 4, 'products' => 4], $found, 'the card, not the menu linking to the category, nor the grid holding them all');
        $this->assertNull(ThemeCards::check($this->wooPage(), '.products', self::PATHS), 'the grid holds four products');
        $this->assertNull(ThemeCards::check($this->wooPage(), '.thumb', self::PATHS), 'a picture holds no link');
        $this->assertNull(ThemeCards::check($this->wooPage(), 'li[data-x] > a', self::PATHS), 'only a tag and classes');
        $this->assertNull(ThemeCards::find('<html><body><a href="https://shop.test/product/board-1/">x</a></body></html>', self::PATHS), 'one product is no list of cards');
    }

    public function test_the_manager_finds_the_cards_with_a_button_and_a_model_helps_only_when_code_cannot(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => 'c1', 'name' => 'פלטות', 'path' => ['פלטות'], 'depth' => 0, 'hash' => 'c1', 'product_count' => 4, 'url' => 'https://shop.test/products/boards/']);
            foreach (self::PATHS as $i => $url) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) ($i + 1), 'type' => 'simple', 'status' => 'publish', 'title' => 'פלטה '.($i + 1),
                    'url' => $url, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$i}", 'payload' => []]);
            }
        });
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly ThemeCardsTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->asked++;

                return new ModelReply(['selector' => array_shift($this->test->replies) ?? ''], 700, 12);
            }
        });

        $merchant = User::factory()->create();
        $merchant->attachShop($shop, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        // Code finds them: no model.
        $this->page = $this->wooPage();
        Http::fake(fn () => Http::response($this->page, $this->status));
        Livewire::test(Wishlist::class)->call('findCards')->assertSet('data.card_selector', '.product-card');
        $this->assertSame(0, $this->asked);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://shop.test/products/boards/');

        // A theme without pictures in its cards: code finds none, the model's answer is checked.
        $this->replies = ['.row', '.tile'];
        $this->page = $this->bareTiles();
        $refused = app(FindThemeCards::class)->handle($shop->id);
        $this->assertFalse($refused['found'], 'a row holds two products: refused by code');
        $kept = app(FindThemeCards::class)->handle($shop->id);
        $this->assertSame([true, '.tile', 4, 'model'], [$kept['found'], $kept['selector'], $kept['cards'], $kept['by']]);
        $this->assertSame(2, $this->asked);
        $this->assertSame(3, Run::query()->where('agent', FindThemeCards::AGENT)->count(), 'every check is a run, with the pages it read');

        // A store that answers our server with a block page: nothing is guessed, and the manager sees why.
        [$this->page, $this->status] = ['Access denied', 403];
        $blocked = app(FindThemeCards::class)->handle($shop->id, 'https://elsewhere.test/collections/all');
        $this->assertSame([false, 'no_links'], [$blocked['found'], $blocked['reason']]);
        $this->assertSame([403, 0], [$blocked['tried'][0]['status'], $blocked['tried'][0]['links']]);
        $this->assertNotContains('https://elsewhere.test/collections/all', array_column($blocked['tried'], 'page'), 'only the store\x27s own pages');
        $this->assertSame(2, $this->asked, 'no model for a page with no product on it');
        $this->assertSame('openai', (string) Settings::get('shortlist.card_finder_provider'));
    }

    private function wooPage(): string
    {
        $cards = '';
        foreach (self::PATHS as $i => $url) {
            $cards .= '<li class="product-card type-product post-'.(100 + $i).' is-loaded"><a href="'.$url.'"><div class="thumb"><img src="x.jpg"></div><h2>פלטה</h2></a><span class="price">100</span><a href="'.$url.'" class="button">לצפייה</a></li>';
        }

        return '<html><body><nav><a href="https://shop.test/products/boards/">פלטות</a></nav><main><ul class="products columns-4">'.$cards.'</ul></main></body></html>';
    }

    private function bareTiles(): string
    {
        $rows = '';
        foreach (array_chunk(self::PATHS, 2) as $pair) {
            $rows .= '<div class="row">';
            foreach ($pair as $url) {
                $rows .= '<div class="tile"><a href="'.$url.'">פלטה</a><b>100</b></div>';
            }
            $rows .= '</div>';
        }

        return '<html><body><section class="grid">'.$rows.'</section></body></html>';
    }
}
