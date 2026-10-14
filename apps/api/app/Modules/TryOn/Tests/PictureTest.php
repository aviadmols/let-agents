<?php

namespace App\Modules\TryOn\Tests;

use App\Modules\TryOn\Support\Picture;
use PHPUnit\Framework\TestCase;

/** The product picture is cut to the product before an image model sees it; anything else is left as it was. */
final class PictureTest extends TestCase
{
    public function test_a_small_product_on_a_plain_background_is_cut_to_itself(): void
    {
        // A 1920x1080 grey studio picture with a 160x60 ring in the middle.
        $image = imagecreatetruecolor(1920, 1080);
        imagefill($image, 0, 0, imagecolorallocate($image, 232, 232, 230));
        imagefilledrectangle($image, 880, 510, 1040, 570, imagecolorallocate($image, 190, 140, 70));
        $cut = imagecreatefromstring(Picture::cropToProduct(self::png($image)));

        $this->assertSame(imagesx($cut), imagesy($cut), 'square');
        $this->assertLessThan(300, imagesx($cut), 'the ring fills it now, with a margin');
        $this->assertGreaterThan(160, imagesx($cut), 'all of the ring is in it');
    }

    public function test_a_picture_without_a_plain_background_or_already_filled_is_left_as_it_was(): void
    {
        // A scene: its corners differ.
        $scene = imagecreatetruecolor(800, 600);
        imagefill($scene, 0, 0, imagecolorallocate($scene, 240, 240, 240));
        imagefilledrectangle($scene, 700, 500, 799, 599, imagecolorallocate($scene, 20, 60, 30));
        $bytes = self::png($scene);
        $this->assertSame($bytes, Picture::cropToProduct($bytes));

        // A product that already fills its picture.
        $full = imagecreatetruecolor(400, 400);
        imagefill($full, 0, 0, imagecolorallocate($full, 250, 250, 250));
        imagefilledrectangle($full, 20, 20, 380, 380, imagecolorallocate($full, 120, 90, 40));
        $bytes = self::png($full);
        $this->assertSame($bytes, Picture::cropToProduct($bytes));

        $this->assertSame('not a picture', Picture::cropToProduct('not a picture'));
    }

    private static function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
