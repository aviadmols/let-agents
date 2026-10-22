<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\Onboarding;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The first-run screens read the modules themselves: the store's products and pages (Catalog), what its
 * product pages repeat (the assistant's knowledge, shipping first), the answers ready, and where the scan
 * is (Runs), part by part. What the store stopped publishing is not counted.
 */
final class OnboardingFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_what_lets_found_and_the_scan_come_from_the_stores_own_data(): void
    {
        Bus::fake();
        $shop = Shop::factory()->onboarding()->create(['name' => 'גואטה אביגדור']);
        $owner = User::factory()->create(['locale' => 'he']);
        $owner->attachShop($shop, ShopRole::Owner);

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1', '2', '3'] as $id) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'פלטה '.$id, 'url' => 'https://store.test/'.$id, 'price' => 54, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$id, 'payload' => [], 'removed_at' => $id === '3' ? now() : null]);
            }
            CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => 'p1', 'title' => 'משלוחים', 'url' => 'https://store.test/shipping', 'body' => 'משלוח', 'hash' => 'a']);
            CatalogContent::query()->create(['type' => CatalogContent::POST, 'external_id' => 'p2', 'title' => 'מדריך', 'url' => 'https://store.test/guide', 'body' => 'מדריך', 'hash' => 'b']);
            // what most product pages repeat: a return policy first, then shipping, which a shopper asks first
            CatalogContent::query()->create([
                'type' => CatalogContent::KNOWLEDGE, 'external_id' => BuildShopKnowledge::REPEATED, 'title' => '', 'hash' => 'k',
                'body' => "- החזרה עד 14 יום מיום הקנייה\n- משלוח חינם בהזמנה מעל 300 ₪",
                'terms' => ['pages' => 10, 'counts' => [RepeatedText::key('החזרה עד 14 יום מיום הקנייה') => 9, RepeatedText::key('משלוח חינם בהזמנה מעל 300 ₪') => 7], 'hidden' => []],
            ]);
            foreach (['delivery', 'returns'] as $key) {
                AssistantAnswer::query()->create([
                    'shop_id' => $shop->id, 'question' => $key, 'question_key' => $key, 'answer' => 'כן',
                    'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 0, 'last_asked_at' => now(),
                ]);
            }
        });

        $this->actingAs($owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        Livewire::test(Onboarding::class)->set('step', 2)
            ->assertSee('WooCommerce · 2 מוצרים · 2 דפים ופוסטים')
            ->assertSee('משלוח חינם בהזמנה מעל 300 ₪ · ב-7 עמודי מוצר')
            ->assertDontSee('החזרה עד 14 יום');

        // the scan under way: the products read, the pictures being looked at, the rest still to come
        FirstScan::start($shop);
        FirstScan::mark($shop->id, 'catalog', FirstScan::DONE);
        FirstScan::mark($shop->id, 'sample', FirstScan::DONE);
        FirstScan::mark($shop->id, 'pictures', FirstScan::RUNNING);

        Livewire::test(Onboarding::class)->set('step', 3)
            ->assertSee('Lets מכיר את החנות')
            ->assertSeeHtml('data-scan-row="products" data-state="done"')
            ->assertSeeHtml('data-scan-row="images" data-state="running"')
            ->assertSeeHtml('data-scan-row="pages" data-state="waiting"')
            ->assertSeeHtml('data-scan-row="knowledge" data-state="waiting"')
            ->assertSeeHtml('wire:poll.10s')
            ->assertSee('ממשיך להתעדכן ברקע')
            ->assertSeeHtmlInOrder(['data-scan-row="products"', '<span data-n>2</span>', 'data-scan-row="images"', 'מתוך 2', 'data-scan-row="knowledge"', '<span data-n>2</span>']);
    }
}
