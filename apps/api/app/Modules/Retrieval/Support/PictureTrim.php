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
    /**
     * Bump when the trimming changes. 2: a shaded backdrop is trimmed too (Rocks, 28.10: a ring in the middle of a grey
     * sweep was compared whole, and found the rings photographed on the same sweep). A picture is embedded again under
     * a new version only when its cut changed (changedSince).
     */
    public const VERSION = 2;

    /** Between neighbouring pixels along a shaded backdrop's edge, at most this far apart (a smooth sweep). */
    private const SHADED_STEP = 14;

    /** The share of an edge's steps that may be rougher than that (JPEG noise, a watermark's corner). */
    private const SHADED_ROUGH = 0.02;

    /** A row or a column holds the product when this many of its pixels differ from the backdrop. */
    private const SHADED_SPECK = 2;

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
        return self::cut($bytes)['data'] ?? null;
    }

    /**
     * Whether a picture embedded under an older version is prepared differently now, by how cut() read its background
     * now (null: left whole). Only a backdrop version 2 reads (shaded) is: what version 1 trimmed, or left whole, is the
     * same picture, and needs no new vector.
     */
    public static function changedSince(int $version, ?string $background): bool
    {
        return $version < 2 && $background === 'shaded';
    }

    /**
     * The trimmed picture and how its background was read: "plain" (one colour, as version 1 read it) or "shaded" (a
     * studio backdrop that grows lighter or darker from top to bottom, version 2). Null when there is nothing to trim.
     * A picture version 1 trimmed is trimmed exactly as before; only one it left whole can be trimmed now.
     *
     * @return array{data: string, background: string}|null
     */
    public static function cut(string $bytes): ?array
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
        $plain = $background === null ? null : self::plainBox($probe, $pw, $ph, $background);
        $data = $plain === null ? null : self::crop($image, $width, $height, $scale, $plain);

        if ($data !== null) {
            return ['data' => $data, 'background' => 'plain'];
        }

        $shaded = self::shadedBox($probe, $pw, $ph);
        $data = $shaded === null ? null : self::crop($image, $width, $height, $scale, $shaded);

        return $data === null ? null : ['data' => $data, 'background' => 'shaded'];
    }

    /**
     * What differs from the one background colour (version 1).
     *
     * @return array{int, int, int, int}|null left, top, right, bottom on the probe
     */
    private static function plainBox(\GdImage $probe, int $pw, int $ph, int $background): ?array
    {
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

        return $right < $left || $bottom < $top ? null : [$left, $top, $right, $bottom];
    }

    /**
     * What differs from a backdrop that changes from top to bottom (a grey studio sweep, darker above): each row's
     * background is read at its two ends, and between them it is taken to change evenly. Only a backdrop whose edges
     * all run smoothly counts: a product or a person touching an edge breaks it, and the picture stays whole.
     *
     * @return array{int, int, int, int}|null left, top, right, bottom on the probe
     */
    private static function shadedBox(\GdImage $probe, int $pw, int $ph): ?array
    {
        $edge = [];
        foreach ([[0, 0, 1, 0, $pw], [0, $ph - 1, 1, 0, $pw], [0, 0, 0, 1, $ph], [$pw - 1, 0, 0, 1, $ph]] as [$x, $y, $dx, $dy, $n]) {
            $line = [];
            for ($i = 0; $i < $n; $i++) {
                $line[] = imagecolorat($probe, $x + $dx * $i, $y + $dy * $i);
            }
            $edge[] = $line;
        }

        // every edge smooth: no step between neighbours a product's outline would make, and not one a hard edge would
        $steps = 0;
        $rough = 0;
        foreach ($edge as $line) {
            for ($i = 1; $i < count($line); $i++) {
                $steps++;
                $step = self::distance($line[$i], $line[$i - 1]);
                if ($step > self::TOLERANCE) {
                    return null;
                }
                $rough += $step > self::SHADED_STEP ? 1 : 0;
            }
        }
        if ($steps === 0 || $rough > $steps * self::SHADED_ROUGH) {
            return null;
        }

        [$left, $top, $right, $bottom] = [$pw, $ph, -1, -1];
        $columns = array_fill(0, $pw, 0);
        $rows = array_fill(0, $ph, 0);

        for ($y = 0; $y < $ph; $y++) {
            $a = $edge[2][$y];
            $b = $edge[3][$y];
            for ($x = 0; $x < $pw; $x++) {
                $t = $pw > 1 ? $x / ($pw - 1) : 0.0;
                $expected = self::mix($a, $b, $t);
                if (self::distance(imagecolorat($probe, $x, $y), $expected) > self::TOLERANCE) {
                    $columns[$x]++;
                    $rows[$y]++;
                }
            }
        }

        // a lone speck is noise, not the product: a row or a column counts with a few of its pixels
        foreach ($columns as $x => $n) {
            if ($n >= self::SHADED_SPECK) {
                $left = min($left, $x);
                $right = max($right, $x);
            }
        }
        foreach ($rows as $y => $n) {
            if ($n >= self::SHADED_SPECK) {
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }

        return $right < $left || $bottom < $top ? null : [$left, $top, $right, $bottom];
    }

    /**
     * The original cut to a box found on the probe, with a margin; null when it would keep most of the picture.
     *
     * @param  array{int, int, int, int}  $box
     */
    private static function crop(\GdImage $image, int $width, int $height, float $scale, array $box): ?string
    {
        [$left, $top, $right, $bottom] = $box;

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

    /** The colour a share $t of the way from $a to $b. */
    private static function mix(int $a, int $b, float $t): int
    {
        $channel = fn (int $shift): int => (int) round((($a >> $shift) & 255) * (1 - $t) + (($b >> $shift) & 255) * $t);

        return ($channel(16) << 16) | ($channel(8) << 8) | $channel(0);
    }

    private static function distance(int $a, int $b): float
    {
        return sqrt(((($a >> 16) & 255) - (($b >> 16) & 255)) ** 2 + ((($a >> 8) & 255) - (($b >> 8) & 255)) ** 2 + (($a & 255) - ($b & 255)) ** 2);
    }
}
