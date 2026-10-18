<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An answer the team wrote is on the operator's timeline as something a user did. */
final class AssistantTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_answer_is_on_the_timeline(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'פלטת לביד MDF', 'url' => 'https://store.test/1', 'price' => 54, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
            AssistantAnswer::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'question' => 'האם אפשר לחתוך לפי מידה?', 'question_key' => 'cut', 'answer' => 'כן, בחנות בלבד.',
                'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'source' => 'team', 'prompt_version' => 1, 'asked_count' => 9, 'last_asked_at' => now()]);
            AssistantAnswer::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'question' => 'יש משלוח לאילת?', 'question_key' => 'eilat', 'answer' => 'כן.',
                'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 2, 'last_asked_at' => now()]);
        });

        $this->actingAs(User::factory()->operator()->create())->withHeader('Accept-Language', 'he')
            ->get('/operator/timeline?shop='.$shop->slug.'&kind=user')
            ->assertOk()
            ->assertSee('הצוות ענה: ״האם אפשר לחתוך לפי מידה?״')
            ->assertSee('בעמוד: פלטת לביד MDF')
            ->assertDontSee('יש משלוח לאילת?');
    }
}
