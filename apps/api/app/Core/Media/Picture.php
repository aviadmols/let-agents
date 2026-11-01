<?php

namespace App\Core\Media;

use GdImage;

/**
 * A picture's bytes made an image, when they are one of a sane size (1.11). The header is read first: a small file can
 * say it is 30000×30000 pixels, and opening it would take gigabytes of a worker's memory. A phone's photo, even a large
 * one, is far below the cap.
 */
final class Picture
{
    public const MAX_PIXELS = 40_000_000;

    public static function decode(string $bytes, int $maxPixels = self::MAX_PIXELS): GdImage|false
    {
        if ($bytes === '' || ! self::sane($bytes, $maxPixels)) {
            return false;
        }

        return @imagecreatefromstring($bytes);
    }

    /** Whether the bytes say they are a picture no larger than the cap. */
    public static function sane(string $bytes, int $maxPixels = self::MAX_PIXELS): bool
    {
        $size = @getimagesizefromstring($bytes);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 && $maxPixels >= $size[0] * $size[1];
    }
}
