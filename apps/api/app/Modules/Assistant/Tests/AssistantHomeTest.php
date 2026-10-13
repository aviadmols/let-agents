<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A question the assistant could not answer waits on the shop's home, and on its notices. */
final class AssistantHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_question_waits_on_the_home_with_the_way_to_answer_it(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create(['name' => 'דנה כהן']);
        $merchant->attachShop($shop);

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת', 'url' => 'https://store.test/1', 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
            AssistantAnswer::query()->create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'question' => 'אפשר להחזיר?', 'question_key' => 'return', 'answer' => '',
                'outcome' => AssistantAnswer::NO_INFO, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 4, 'last_asked_at' => now(),
            ]);
        });

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('שאלה אחת של גולשים מחכה לתשובה')
            ->assertSee('״אפשר להחזיר?״ נשאלה 4 פעמים');

        $this->get("/merchant/{$shop->slug}/notices")->assertOk()->assertSee('לענות עכשיו');
    }
}
