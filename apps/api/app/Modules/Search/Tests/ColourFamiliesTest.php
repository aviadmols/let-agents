<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A model the store sells as a product a colour (Chozen, 28.10: "פלדיום מגפיים PAMPA HI לגברים" in beige and in brown)
 * is one family, each of its cards showing the others' colours; and a product's colour is what the store says it is,
 * its title or "בצבע" in its description, before the colour of a detail its picture names: a beige boot is no blue boot.
 */
final class ColourFamiliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_model_in_colours_is_a_family_and_its_colours_are_the_stores_words(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                ['1', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'נעליים PAMPA לגברים בצבע בז\' עם סגירת שרוכים בצבע בז\'.'],
                ['2', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'פלדיום מגפיים PAMPA HI לגברים בצבע חום, עשויים מבד קנבס.'],
                ['3', 'פלדיום מגפיים PAMPA HI לגברים', 'PALLADIUM', 'מגפיים בצבע שחור.'],
                ['4', 'אדידס סניקרס SAMBA OG לגברים', 'ADIDAS', 'סניקרס בצבע כחול כהה עם פסי המותג בצבע שמנת.'],
                ['5', 'פלדיום מגפיים BAGGY לגברים', 'PALLADIUM', 'מגפיים בצבע שחור.'],
            ] as [$id, $title, $brand, $text]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                    'url' => "https://store.test/p/{$id}", 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
                    'payload' => ['description' => '<p>'.$text.'</p>', 'tags' => ['נעליים']]]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $items = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'], null, 'id');

        $this->assertSame(['בז'], $items['p:1']['mc'], 'beige, as the store says it, though no colour word in its title');
        $this->assertSame(['חומ'], $items['p:2']['mc']);
        $this->assertSame(['כחול'], $items['p:4']['mc'], 'its first colour, never its stripes\'');
        $this->assertNotNull($items['p:1']['cf'] ?? null);
        $this->assertSame($items['p:1']['cf'], $items['p:2']['cf']);
        $this->assertSame($items['p:1']['cf'], $items['p:3']['cf'], 'one model in three colours');
        $this->assertArrayNotHasKey('cf', $items['p:5'], 'another model of the brand');
        $this->assertArrayNotHasKey('cf', $items['p:4'], 'a model in one colour');
    }
}
