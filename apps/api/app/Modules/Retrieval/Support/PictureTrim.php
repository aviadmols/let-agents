<?php

namespace App\Modules\Retrieval\Support;

/**
 * A product picture without the plain background around the product: a small ring in the middle
 * of a large white frame is mostly white to a picture model, and a shopper's photo of a ring on a
 * hand is compared with the ring, not with the white. The background is the colour of the corners;
 * what differs from it is the product, kept with a margin. Code only, GD.
 */
final class PictureTrim
{
    /** Bump when the trimming changes: every picture is embedded again under the new one. */
    public const VERSION = 1;

    /** The picture is measured at this size; the cut is made on the original. */
    private const PROBE = 256;

    /** How far (0 to 441) a pixel's colour is from the background's before it counts as the product. */
    private const TOLERANCE = 40;

    private const MARGIN = 0.08;

    /** Only a cut that leaves at most this share of the area is worth making. */
    private const WORTH = 0.75;

    /**
     * The picture trimmed to its product, as JPEG; null when it cannot be read, the background is not
     * plain, or there is little to trim. The caller keeps the original then.
     */
    public static function trim(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        [$width, $height] = [imagesx($image), imagesy($image)];

        if ($width < 32 || $height < 32) {
            return null;
        }

        $scale = min(1, self::PROBE / max($width, $height));
        $probe = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        if ($probe === false) {
            return null;
        }

        [$pw, $ph] = [imagesx($probe), imagesy($probe)];
        $background = self::background($probe, $pw, $ph);

        if ($background === null) {
            return null;
        }

        [$left, $top, $right, $bottom] = [$pw, $ph, -1, -1];

        for ($y = 0; $y < $ph; $y++) {
            for ($x = 0; $x < $pw; $x++) {
                if (self::distance(imagecolorat($probe, $x, $y), $background) > self::TOLERANCE) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if ($right < $left || $bottom < $top) {
            return null;
        }

        // Back to the original's pixels, with a margin, kept inside the picture.
        $padX = ($right - $left + 1) * self::MARGIN;
        $padY = ($bottom - $top + 1) * self::MARGIN;
        $x = (int) max(0, floor(($left - $padX) / $scale));
        $y = (int) max(0, floor(($top - $padY) / $scale));
        $w = (int) min($width - $x, ceil(($right - $left + 1 + 2 * $padX) / $scale));
        $h = (int) min($height - $y, ceil(($bottom - $top + 1 + 2 * $padY) / $scale));

        if ($w * $h > self::WORTH * $width * $height || $w < 16 || $h < 16) {
            return null;
        }

        $cut = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);

        if ($cut === false) {
            return null;
        }

        ob_start();
        imagejpeg($cut, null, 92);

        return (string) ob_get_clean();
    }

    /** The colour of the four corners when they agree, as the background; null when they do not. */
    private static function background(\GdImage $image, int $w, int $h): ?int
    {
        $corners = [imagecolorat($image, 0, 0), imagecolorat($image, $w - 1, 0), imagecolorat($image, 0, $h - 1), imagecolorat($image, $w - 1, $h - 1)];

        foreach ($corners as $corner) {
            if (self::distance($corner, $corners[0]) > self::TOLERANCE) {
                return null;
            }
        }

        return $corners[0];
    }

    private static function distance(int $a, int $b): float
    {
        return sqrt(((($a >> 16) & 255) - (($b >> 16) & 255)) ** 2 + ((($a >> 8) & 255) - (($b >> 8) & 255)) ** 2 + (($a & 255) - ($b & 255)) ** 2);
    }
}
