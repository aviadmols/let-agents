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
}
