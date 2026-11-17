<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "נגד ציפורים" in a store whose spike is written "להרחקת יונים": no product says "ציפורים", and the store's assistant answered
 * "מה מתאים נגד ציפורים?" with the spike. The search shows what that answer picked, first (Gueta, 17.11).
 */
final class AnsweredWithTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_word_only_an_answered_question_says_shows_what_the_answer_picked(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'דוקרן נירוסטה להרחקת יונים', '2' => 'ספריי צבע נגד חלודה', '3' => 'רשת נגד יתושים', '4' => 'פטיש נגרים'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => (string) $id !== '4', 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
            $answer = fn (array $extra): AssistantAnswer => AssistantAnswer::query()->create(array_merge([
                'shop_id' => $shop->id, 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 1, 'last_asked_at' => now(),
            ], $extra));
            $answer(['question' => 'מה מתאים נגד ציפורים?', 'question_key' => 'k1', 'answer' => 'דוקרן להרחקת יונים.', 'picks' => [['external_id' => '1', 'title' => 'דוקרן נירוסטה להרחקת יונים']]]);
            // one not in stock is not shown, and one the store hid vouches for nothing
            $answer(['question' => 'מה מתאים לתליית ערסל?', 'question_key' => 'k2', 'answer' => 'בפטיש נגרים.', 'picks' => [['external_id' => '4']]]);
            $answer(['question' => 'מה טוב לעטלפים?', 'question_key' => 'k3', 'answer' => 'רשת.', 'picks' => [['external_id' => '3']], 'status' => AssistantAnswer::HIDDEN]);
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $ids = fn (string $q): array => array_column(app(SearchCatalog::class)->handle($shop->id, $q, ['product'], false, null, true)['groups']['product'], 'external_id');

        $this->assertSame('1', $ids('נגד ציפורים')[0] ?? null, 'the answered question says the word no product does');
        $this->assertSame('1', $ids('ציפורים')[0] ?? null);
        $this->assertSame('1', $ids('משהו נגד ציפורים')[0] ?? null, 'a word that names nothing asks for nothing');
        $this->assertSame('1', $ids('הרחקת ציפורים')[0] ?? null, 'a word the product itself says');
        $this->assertNotContains('1', $ids('כלוב ציפורים'), 'a word neither the question nor the product says');
        $this->assertNotContains('4', $ids('ערסל'), 'not in stock');
        $this->assertNotContains('3', $ids('עטלפים'), 'an answer the store hid');
        $this->assertNotContains('1', $ids('נגד חלודה'), 'words the products say are searched as ever');
    }
}
