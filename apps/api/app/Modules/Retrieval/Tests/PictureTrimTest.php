<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Retrieval\Support\PictureTrim;
use Tests\TestCase;

/** A small product in a large plain frame is cut down to the product; a busy picture is left whole. */
final class PictureTrimTest extends TestCase
{
    public function test_a_small_ring_on_white_is_cut_down_to_the_ring_with_a_margin(): void
    {
        $image = imagecreatetruecolor(600, 340);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, 260, 140, 340, 190, imagecolorallocate($image, 180, 140, 60));

        $trimmed = PictureTrim::trim(self::jpeg($image));

        $this->assertNotNull($trimmed);
        $cut = imagecreatefromstring($trimmed);
        $this->assertLessThan(130, imagesx($cut), 'the ring and a margin, not the frame');
        $this->assertGreaterThan(80, imagesx($cut), 'the whole ring is kept');
    }

    public function test_a_picture_with_no_plain_background_or_little_to_trim_is_left_as_it_is(): void
    {
        $busy = imagecreatetruecolor(300, 300);
        imagefilledrectangle($busy, 0, 0, 149, 299, imagecolorallocate($busy, 20, 120, 40));
        imagefilledrectangle($busy, 150, 0, 299, 299, imagecolorallocate($busy, 200, 30, 30));
        $this->assertNull(PictureTrim::trim(self::jpeg($busy)), 'the corners disagree: no background to trim');

        $full = imagecreatetruecolor(300, 300);
        imagefill($full, 0, 0, imagecolorallocate($full, 255, 255, 255));
        imagefilledrectangle($full, 10, 10, 290, 290, imagecolorallocate($full, 30, 30, 30));
        $this->assertNull(PictureTrim::trim(self::jpeg($full)), 'the product fills the picture');
    }

    private static function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }
}
