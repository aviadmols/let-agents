<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Support\StoreSpelling;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A misspelled word read as the store's own (Rocks, 9.11: "מה זאת תוכנית lawyawy?" refused as not about the store):
 * the store's words are its pages' and its products' titles, a word one or two letters off is read as the nearest of
 * them, and nothing the store does not write, nothing short and nothing already the store's is touched.
 */
final class StoreSpellingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_misspelled_word_of_the_stores_own_is_read_as_it_and_nothing_else_is_touched(): void
    {
        $shop = Shop::factory()->create();
        $run = fn (callable $callback): mixed => app(TenantContext::class)->run($shop->id, $callback);

        $run(function () use ($shop): void {
            CatalogContent::query()->create(['type' => 'page', 'external_id' => 'layaway', 'title' => 'תוכנית Layaway', 'url' => 'https://rings.test/pages/layaway', 'body' => '<p>שריון תכשיט עם מקדמה של 10%, ותשלומים עד 10 חודשים.</p>', 'hash' => 'l1']);
            CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת סוליטר משובצת יהלום', 'url' => 'https://rings.test/1', 'price' => 1250, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
        });

        $fix = fn (string $question): ?string => $run(fn (): ?string => StoreSpelling::fix($shop->id, $question));

        $this->assertSame('מה זאת תוכנית Layaway?', $fix('מה זאת תוכנית lawyawy?'), 'two letters off a word of seven: the store\'s word, as it writes it');
        $this->assertSame('יש טבעת סוליטר משובצת?', $fix('יש טבעת סולטר משובצת?'), 'Hebrew, one letter off');
        $this->assertNull($fix('מה זאת תוכנית Layaway?'), 'the store\'s own word stays');
        $this->assertNull($fix('אתם מחליפים סוללות לשעונים?'), 'nothing the store writes is near: nothing changes');
        $this->assertNull($fix('יש טבע?'), 'a short word is never read as another');
        $this->assertNull(app(TenantContext::class)->run(Shop::factory()->create()->id, fn (): ?string => StoreSpelling::fix('other', 'מה זאת תוכנית lawyawy?')), 'another store\'s words are not this one\'s');
    }
}
