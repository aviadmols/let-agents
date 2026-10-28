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

    public function test_a_ring_on_a_grey_sweep_darker_above_is_cut_down_to_the_ring(): void
    {
        $image = self::sweep(600, 340);
        imagefilledrectangle($image, 260, 140, 340, 190, imagecolorallocate($image, 40, 50, 110));

        $this->assertNull(self::plainOnly($image), 'version 1 read no one background: its corners differ');
        $cut = PictureTrim::cut(self::jpeg($image));

        $this->assertSame('shaded', $cut['background'] ?? null);
        $ring = imagecreatefromstring((string) $cut['data']);
        $this->assertLessThan(130, imagesx($ring), 'the ring and a margin, not the sweep');
        $this->assertGreaterThan(80, imagesx($ring), 'the whole ring is kept');
    }

    public function test_on_a_sweep_what_touches_an_edge_is_left_whole_and_a_plain_frame_is_cut_as_before(): void
    {
        // a person standing to the bottom edge: the edge is not a smooth backdrop there
        $standing = self::sweep(400, 500);
        imagefilledrectangle($standing, 150, 80, 250, 499, imagecolorallocate($standing, 30, 30, 30));
        $this->assertNull(PictureTrim::cut(self::jpeg($standing)));

        $white = imagecreatetruecolor(600, 340);
        imagefill($white, 0, 0, imagecolorallocate($white, 250, 250, 250));
        imagefilledrectangle($white, 260, 140, 340, 190, imagecolorallocate($white, 180, 140, 60));
        $this->assertSame('plain', PictureTrim::cut(self::jpeg($white))['background'] ?? null);
        $this->assertSame(PictureTrim::trim(self::jpeg($white)), PictureTrim::cut(self::jpeg($white))['data'] ?? null);
    }

    public function test_only_a_picture_cut_differently_now_needs_a_new_vector(): void
    {
        $this->assertTrue(PictureTrim::changedSince(1, 'shaded'), 'left whole before, cut now');
        $this->assertFalse(PictureTrim::changedSince(1, 'plain'), 'cut the same way before');
        $this->assertFalse(PictureTrim::changedSince(1, null), 'whole before, whole now');
        $this->assertFalse(PictureTrim::changedSince(2, 'shaded'), 'already under this version');
    }

    /** A studio sweep: grey, darker at the top, lighter at the bottom, the same across each row. */
    public static function sweep(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $grey = (int) round(190 + 36 * $y / ($height - 1));
            imageline($image, 0, $y, $width - 1, $y, imagecolorallocate($image, $grey, $grey + 2, $grey + 4));
        }

        return $image;
    }

    /** Whether version 1 alone (one background colour, the corners') would have trimmed it. */
    private static function plainOnly(\GdImage $image): ?string
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $corners = [imagecolorat($image, 0, 0), imagecolorat($image, $w - 1, $h - 1)];
        $distance = sqrt(array_sum(array_map(fn (int $s): int => ((($corners[0] >> $s) & 255) - (($corners[1] >> $s) & 255)) ** 2, [16, 8, 0])));

        return $distance > 40 ? null : 'plain';
    }

    private static function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }
}
