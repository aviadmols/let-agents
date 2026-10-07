<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Contracts\PageTags;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tag view: "you may also want to see" over the page's tags, each a section with the usual
 * product cards and guides. The other views never carry them.
 */
final class TagBankTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $this->app->instance(PageTags::class, new class implements PageTags
        {
            public function forPage(string $shopId, string $type, string $externalId, int $perTag): array
            {
                return [
                    ['label' => 'ברגים לדק', 'query' => 'בורג נירוסטה', 'products' => ['203', '202', '999'], 'content' => []],
                    ['label' => 'מדריכי דק', 'query' => 'בונים דק', 'products' => [], 'content' => ['600']],
                    ['label' => 'נעלם', 'query' => 'x', 'products' => ['999'], 'content' => []],
                ];
            }
        });

        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['201' => 'עץ איפאה לדק 21X145', '202' => 'בורג נירוסטה לדק 5X50', '203' => 'בורג נירוסטה לדק 5X60'] as $id => $title) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish',
                    'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'image_url' => "https://www.store.test/i/{$id}.jpg",
                    'price' => '49.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                ]);
            }
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '600', 'title' => 'איך בונים דק',
                'url' => 'https://www.store.test/guide/deck', 'terms' => [], 'hash' => 'h600',
            ]);
        });
        Features::override('widget.on_products', true, $this->shop->id);
    }

    public function test_the_tag_view_carries_the_tags_as_sections_and_the_other_views_do_not(): void
    {
        Settings::set('widget.layout', 'tags', $this->shop->id);
        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '201', 'he');

        $this->assertSame('tags', $bank['layout']);
        $this->assertSame(['ברגים לדק', 'מדריכי דק'], array_column($bank['tags'], 'chip'), 'a tag whose products are all gone is not shown');
        $this->assertSame(['tag', 'tag'], array_column($bank['tags'], 'candidate'));
        $this->assertSame(['tag_1', 'tag_2'], array_column($bank['tags'], 'key'));
        $this->assertSame(['בורג נירוסטה לדק 5X60', 'בורג נירוסטה לדק 5X50'], array_column($bank['tags'][0]['products'], 'title'), 'in the order the search found them');
        $this->assertArrayNotHasKey('products', $bank['tags'][1], 'a guide-only tag has no empty product row');
        $this->assertSame('איך בונים דק', $bank['tags'][1]['guides'][0]['title']);
        $this->assertSame('תרצו לראות גם', $bank['labels']['tags_title']);

        Settings::set('widget.layout', 'circles', $this->shop->id);
        $this->assertSame([], app(BuildPageBank::class)->handle($this->shop->id, 'product', '201', 'he')['tags']);
    }

    public function test_the_field_gets_the_answers_this_page_already_gave_and_nothing_else(): void
    {
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $page = CatalogProduct::query()->where('external_id', '201')->value('id');
            $other = CatalogProduct::query()->where('external_id', '202')->value('id');
            $answer = fn (array $extra) => AssistantAnswer::query()->create(array_merge([
                'shop_id' => $this->shop->id, 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 3, 'last_asked_at' => now(),
            ], $extra));

            $answer(['product_id' => $page, 'question_key' => 'k1', 'question' => 'כל כמה זמן לשמן?', 'answer' => 'פעם בשנה.', 'asked_count' => 5]);
            $answer(['product_id' => $page, 'question_key' => 'k2', 'question' => 'מוסתר?', 'answer' => 'כן.', 'status' => AssistantAnswer::HIDDEN]);
            $answer(['product_id' => $other, 'question_key' => 'k3', 'question' => 'על מוצר אחר?', 'answer' => 'כן.']);
        });

        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '201', 'he');

        $this->assertTrue($bank['find']);
        $this->assertSame([['q' => 'כל כמה זמן לשמן?', 'a' => 'פעם בשנה.']], $bank['answers']);
        $this->assertSame('חפשו או שאלו על המוצר…', $bank['labels']['find_placeholder']);

        Features::override('widget.find_field', false, $this->shop->id);
        $off = app(BuildPageBank::class)->handle($this->shop->id, 'product', '201', 'he');
        $this->assertFalse($off['find']);
        $this->assertSame([], $off['answers']);
    }
}
