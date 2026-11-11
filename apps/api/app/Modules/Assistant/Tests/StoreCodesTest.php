<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Support\StoreCodes;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A question that names a code the store writes is the store's (Rocks 11.11: "מה הכוונה ב GH ו VS" refused as not about
 * the store, its rings' grades too far by meaning): a code is capitals or letters with digits, written on the store's
 * products or pages; an ordinary word, in any language, never is one.
 */
final class StoreCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_code_the_store_writes_makes_the_question_its_own_and_an_ordinary_word_never_does(): void
    {
        $shop = Shop::factory()->create();
        $run = fn (callable $callback): mixed => app(TenantContext::class)->run($shop->id, $callback);

        $run(function () use ($shop): void {
            CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת סוליטר יהלום 0.5 קראט', 'url' => 'https://rings.test/1', 'price' => 6400, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1',
                'payload' => ['description' => '<p>יהלום בצבע GH וניקיון VS, משובץ בזהב 14K.</p>']]);
            CatalogContent::query()->create(['type' => 'page', 'external_id' => 'care', 'title' => 'Care guide', 'url' => 'https://rings.test/pages/care', 'body' => '<p>Keep the ring away from water and the sun.</p>', 'hash' => 'c1']);
        });

        $in = fn (string $question): array => $run(fn (): array => StoreCodes::in($shop->id, $question));

        $this->assertSame(['GH', 'VS'], $in('מה הכוונה ב GH ו VS'));
        $this->assertSame(['VS', 'GH'], $in('מה הכוונה VS GH ?'));
        $this->assertSame(['14k'], $in('זה זהב 14k?'), 'letters and digits, as typed');
        $this->assertSame([], $in('מה זה VVS?'), 'a code the store does not write');
        $this->assertSame([], $in('who wins, the sun or the water?'), 'ordinary words are never codes, though the store writes them');
        $this->assertSame([], $in('מה הכוונה ב gh'), 'lower case letters alone are a word, not a code');
        $this->assertSame([], app(TenantContext::class)->run(($other = Shop::factory()->create())->id, fn (): array => StoreCodes::in($other->id, 'מה הכוונה ב GH ו VS')), 'another store\'s codes are not this one\'s');
    }
}
