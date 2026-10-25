<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** A question reads the pages whose title holds its words, as Hebrew writes them. */
final class PagesByWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_finds_the_page_named_with_its_words_through_plural_and_prefix(): void
    {
        $shop = Shop::factory()->create();

        $found = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => '1', 'title' => 'ביטול עסקה', 'body' => 'ביטול תוך 14 יום.', 'hash' => 'h1']);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => '2', 'title' => 'הסיפור שלנו', 'body' => 'מאז 1990.', 'hash' => 'h2']);

            $method = new ReflectionMethod(AnswerSiteQuestion::class, 'pagesByWords');

            return $method->invoke(app(AnswerSiteQuestion::class), 'מה מדיניות הביטולים?', []);
        });

        $this->assertSame(['ביטול עסקה'], array_column($found, 'title'));
        $this->assertSame('ביטול תוך 14 יום.', $found[0]['text']);
    }

    public function test_a_question_on_returns_finds_the_policy_titled_in_english(): void
    {
        $shop = Shop::factory()->create();

        $found = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => 'p1', 'title' => 'Refund policy', 'body' => 'אפשר להחזיר תוך 14 יום.', 'hash' => 'h1']);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => 'p2', 'title' => 'Shipping', 'body' => 'משלוח עד 5 ימי עסקים.', 'hash' => 'h2']);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => 'p3', 'title' => 'הסיפור שלנו', 'body' => 'מאז 1990.', 'hash' => 'h3']);
            CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'page', 'external_id' => 'p4', 'title' => 'ביטול עסקה', 'body' => 'ביטול תוך 14 יום.', 'hash' => 'h4']);

            $method = new ReflectionMethod(AnswerSiteQuestion::class, 'pagesByWords');
            $answers = app(AnswerSiteQuestion::class);

            return [
                'returns' => array_column($method->invoke($answers, 'מה מדיניות ההחזרות', []), 'title'),
                'verb' => array_column($method->invoke($answers, 'האם אפשר להחזיר מוצר?', []), 'title'),
                'delivery' => array_column($method->invoke($answers, 'תוך כמה זמן יש משלוח?', []), 'title'),
                'story' => array_column($method->invoke($answers, 'מאיפה התכשיט?', []), 'title'),
                // In English the same: a policy in either language.
                'english' => array_column($method->invoke($answers, 'What is your return policy?', []), 'title'),
                'cancel' => array_column($method->invoke($answers, 'Can I cancel my order?', []), 'title'),
            ];
        });

        $this->assertSame(['Refund policy'], $found['returns']);
        $this->assertSame(['Refund policy'], $found['verb']);
        $this->assertSame(['Shipping'], $found['delivery']);
        $this->assertSame([], $found['story']);
        $this->assertSame(['Refund policy'], $found['english']);
        $this->assertSame(['ביטול עסקה'], $found['cancel']);
    }

    public function test_the_article_a_question_names_and_the_meaning_found_is_read_whole_in_place_of_its_pieces(): void
    {
        // Mycolivia: "מה הכי טוב לסרטן?" was given two pieces of the article "פטריות מרפא וסרטן" (2,300 of its 5,400
        // letters) and answered in general words; the article itself says which mushrooms and why.
        $shop = Shop::factory()->create();
        $body = 'פטריות מרפא וסרטן. '.str_repeat('ריישי וטרמטס נחקרו לצד טיפולים. ', 150).'סוף המאמר.';

        $found = app(TenantContext::class)->run($shop->id, function () use ($shop, $body): array {
            $page = CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'post', 'external_id' => 'a1', 'title' => 'פטריות מרפא וסרטן', 'body' => $body, 'hash' => 'h1']);
            $english = CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'post', 'external_id' => 'a2', 'title' => 'Medicinal mushrooms and cancer', 'body' => 'Mushrooms and cancer. '.str_repeat('Reishi and turkey tail were studied. ', 150).'The end.', 'hash' => 'h2']);
            $piece = fn (CatalogContent $c): array => ['source' => 'content', 'source_id' => (string) $c->id, 'external_id' => null, 'title' => $c->title, 'text' => 'a piece', 'similarity' => 0.5];
            $method = new ReflectionMethod(AnswerSiteQuestion::class, 'pagesByWords');
            $answers = app(AnswerSiteQuestion::class);

            return [
                'he' => $method->invoke($answers, 'מה הכי טוב לסרטן?', [$piece($page), $piece($page)]),
                'en' => $method->invoke($answers, 'What is best for cancer?', [$piece($english)]),
                'words_only' => $method->invoke($answers, 'מה הכי טוב לסרטן?', []),
            ];
        });

        $this->assertCount(1, $found['he'], 'the article once, its pieces gone');
        $this->assertStringEndsWith('סוף המאמר.', $found['he'][0]['text'], 'read to its end');
        $this->assertStringEndsWith('The end.', $found['en'][0]['text'], 'in English the same');
        $this->assertSame(1500, mb_strlen($found['words_only'][0]['text']), 'a page only its title found: its opening, as before');
    }
}
