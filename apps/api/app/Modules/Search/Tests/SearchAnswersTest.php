<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Answers shoppers already got are in the search box: a question finds its answer while it is
 * typed, with the page it came from, and a hidden or refused answer never shows.
 */
final class SearchAnswersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_dddddddddddddddddddddddddddddddddddddddddddddddd';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $product = CatalogProduct::query()->create([
                'shop_id' => $this->shop->id, 'external_id' => '201', 'type' => 'simple', 'status' => 'publish',
                'title' => 'עץ איפאה לדק', 'url' => 'https://www.store.test/p/201', 'price' => '89.00', 'currency' => 'ILS',
                'in_stock' => true, 'purchasable' => true, 'hash' => 'h201', 'payload' => [],
            ]);
            $answer = fn (array $extra): AssistantAnswer => AssistantAnswer::query()->create(array_merge([
                'shop_id' => $this->shop->id, 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 1, 'last_asked_at' => now(),
            ], $extra));

            $answer(['question' => 'כל כמה זמן צריך לשמן דק?', 'question_key' => 'k1', 'answer' => 'פעם בשנה, לפני הקיץ.', 'product_id' => $product->id, 'asked_count' => 4]);
            $answer(['question' => 'כמה ברגים צריך למטר דק?', 'question_key' => 'k2', 'answer' => 'בערך 30.', 'sources' => [['title' => 'כמה ברגים צריך למטר דק', 'url' => 'https://www.store.test/g/600', 'type' => 'content']]]);
            $answer(['question' => 'האם מגיע עם אחריות?', 'question_key' => 'k3', 'answer' => 'הוסתר.', 'status' => AssistantAnswer::HIDDEN]);
            $answer(['question' => 'האם יש משלוח?', 'question_key' => 'k4', 'answer' => null, 'outcome' => AssistantAnswer::NO_INFO]);
        });

        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_question_finds_the_answer_already_given_first(): void
    {
        $result = app(SearchCatalog::class)->handle($this->shop->id, 'כמה ברגים למטר', count: false);

        $this->assertSame('כמה ברגים צריך למטר דק?', $result['groups']['answer'][0]['title']);
        $this->assertSame('בערך 30.', $result['groups']['answer'][0]['answer']);
        $this->assertSame('https://www.store.test/g/600', $result['groups']['answer'][0]['sources'][0]['url']);

        $page = app(SearchCatalog::class)->handle($this->shop->id, 'לשמן דק', count: false);
        $this->assertSame('https://www.store.test/p/201', $page['groups']['answer'][0]['sources'][0]['url'], 'an answer given on a page points to that page');
    }

    public function test_a_hidden_or_unanswered_question_is_never_in_the_box(): void
    {
        $index = $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->assertOk()->json();
        $titles = array_column(array_filter($index['items'], fn (array $i): bool => $i['t'] === 'answer'), 'title');

        $this->assertEqualsCanonicalizing(['כל כמה זמן צריך לשמן דק?', 'כמה ברגים צריך למטר דק?'], $titles);
        $this->assertTrue($index['config']['ask'], 'the box may send a question, on Enter');
        $this->assertNull($index['config']['whatsapp'], 'no WhatsApp until the shop sets one');

        Settings::set('search.answers_in_index', 0, $this->shop->id);
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
        $this->assertSame([], app(SearchCatalog::class)->handle($this->shop->id, 'כמה ברגים למטר', count: false)['groups']['answer']);
    }

    public function test_a_whatsapp_number_is_the_shops_own_and_never_a_shared_one(): void
    {
        Features::override('widget.whatsapp', true);
        Settings::set('widget.whatsapp_number', '0501234567');
        $this->assertNull($this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->assertOk()->json('config.whatsapp'), 'a number saved for every shop at once is nobody\x27s');

        Settings::set('widget.whatsapp_number', '0507654321', $this->shop->id);
        $this->assertSame('0507654321', $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->json('config.whatsapp'));
    }
}
