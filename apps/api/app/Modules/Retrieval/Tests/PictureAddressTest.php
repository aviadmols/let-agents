<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Settings;
use App\Modules\Retrieval\Support\PictureAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shopify's pictures are read resized, so none is too big to read: Rocks and Gold's ring 70726 was a 4.9 MB PNG. */
final class PictureAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shopify_picture_is_fetched_resized_and_any_other_as_it_is(): void
    {
        $this->assertSame(
            'https://cdn.shopify.com/s/files/1/0678/7933/8049/files/tmppnj3mu7c.png?v=1788702173&width=1600',
            PictureAddress::fetchable('https://cdn.shopify.com/s/files/1/0678/7933/8049/files/tmppnj3mu7c.png?v=1788702173'),
        );
        $this->assertSame('https://rocksandgold.co.il/cdn/shop/files/a.jpg?width=1600', PictureAddress::fetchable('https://rocksandgold.co.il/cdn/shop/files/a.jpg'), 'the store\'s own address to the same CDN');
        $this->assertSame('https://cdn.shopify.com/s/files/a.jpg?width=800', PictureAddress::fetchable('https://cdn.shopify.com/s/files/a.jpg?width=800'), 'a width already asked for stays');
        $this->assertSame('https://shop.guetaavigdor.co.il/wp-content/uploads/a.jpg', PictureAddress::fetchable('https://shop.guetaavigdor.co.il/wp-content/uploads/a.jpg'), 'WordPress as it is');

        Settings::set('retrieval.image_fetch_width', 0);
        $this->assertSame('https://cdn.shopify.com/s/files/a.png?v=1', PictureAddress::fetchable('https://cdn.shopify.com/s/files/a.png?v=1'), '0: the original');
    }
}
