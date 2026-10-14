<?php

namespace App\Modules\TryOn\Support;

use GdImage;

/**
 * What code can tell of a picture before any model looks at it, and the pictures code makes: the
 * shopper's photo turned upright, made smaller and saved again (which drops its EXIF, its place and
 * its camera), a smaller copy for the models, and three pictures side by side for the checker.
 * GD only.
 */
final class Picture
{
    /** The shorter side of a photo a picture can be made from. */
    public const MIN_SIDE = 500;

    /** The longest side kept. */
    public const KEEP_SIDE = 1600;

    /** The longest side sent to a model. */
    public const MODEL_SIDE = 1024;

    /** Mean brightness (0–255) under which a photo is too dark to read. */
    public const DARK = 35;

    /** Variance of the edges (a Laplacian) under which a photo is too blurred to read. Kept low: a wrong "blurry" costs a shopper. */
    public const BLURRY = 15.0;

    /**
     * @return array{ok: bool, reason: string|null, jpeg: string|null, width: int, height: int}
     */
    public static function inspect(string $bytes): array
    {
        $fail = fn (string $reason, int $w = 0, int $h = 0): array => ['ok' => false, 'reason' => $reason, 'jpeg' => null, 'width' => $w, 'height' => $h];
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return $fail('unreadable');
        }

        $image = self::upright($image, $bytes);
        [$w, $h] = [imagesx($image), imagesy($image)];

        if (min($w, $h) < self::MIN_SIDE) {
            return $fail('too_small', $w, $h);
        }

        $image = self::fit($image, self::KEEP_SIDE);
        [$brightness, $sharpness] = self::measure($image);

        if ($brightness < self::DARK) {
            return $fail('dark', $w, $h);
        }

        if ($sharpness < self::BLURRY) {
            return $fail('blurry', $w, $h);
        }

        return ['ok' => true, 'reason' => null, 'jpeg' => self::jpeg($image, 85), 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    /** A copy small enough for a model, as JPEG. */
    public static function forModel(string $bytes, int $side = self::MODEL_SIDE): string
    {
        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? self::jpeg(self::fit($image, $side), 82) : $bytes;
    }

    /**
     * A product picture cut to the product: a store shoots a ring small in the middle of a large plain
     * background, and the image model then sees too few of its pixels to keep its stones. The
     * background is the colour of the corners; the product is what differs from it; the cut keeps a
     * margin around it and stays square-ish. A picture whose product already fills it, or whose
     * background is not plain, is returned as it was. Code only.
     */
    public static function cropToProduct(string $bytes, float $margin = 0.18): string
    {
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return $bytes;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $corners = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];
        $colours = array_map(fn (array $p): array => self::rgb($image, $p[0], $p[1]), $corners);
        $background = [
            (int) round(array_sum(array_column($colours, 0)) / 4),
            (int) round(array_sum(array_column($colours, 1)) / 4),
            (int) round(array_sum(array_column($colours, 2)) / 4),
        ];

        // Corners that disagree: not a plain background (a photo of the piece worn, a scene).
        foreach ($colours as $c) {
            if (self::distance($c, $background) > 40) {
                return $bytes;
            }
        }

        $step = max(1, (int) floor(min($w, $h) / 300));
        [$left, $top, $right, $bottom] = [$w, $h, -1, -1];

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (self::distance(self::rgb($image, $x, $y), $background) > 48) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if ($right < 0) {
            return $bytes;
        }

        $boxW = $right - $left + 1;
        $boxH = $bottom - $top + 1;

        // Already fills most of the picture: nothing to gain.
        if ($boxW * $boxH > 0.6 * $w * $h) {
            return $bytes;
        }

        $side = (int) round(max($boxW, $boxH) * (1 + 2 * $margin));
        $side = min(max($side, 64), $w, $h);
        $cx = (int) round(($left + $right) / 2);
        $cy = (int) round(($top + $bottom) / 2);
        $x0 = max(0, min($w - $side, $cx - intdiv($side, 2)));
        $y0 = max(0, min($h - $side, $cy - intdiv($side, 2)));
        $cut = imagecrop($image, ['x' => $x0, 'y' => $y0, 'width' => $side, 'height' => $side]);

        return $cut instanceof GdImage ? self::jpeg($cut, 92) : $bytes;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(GdImage $image, int $x, int $y): array
    {
        $c = imagecolorat($image, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    /** @param array{0: int, 1: int, 2: int} $a @param array{0: int, 1: int, 2: int} $b */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * Pictures side by side, each scaled to one height, for a checker that sees one picture at a time:
     * the product, the shopper's photo, the result, in that order.
     *
     * @param  list<string>  $pictures
     */
    public static function sideBySide(array $pictures, int $height = 512): string
    {
        $images = array_values(array_filter(array_map(fn (string $b) => @imagecreatefromstring($b), $pictures), fn ($i): bool => $i instanceof GdImage));
        $widths = array_map(fn (GdImage $i): int => max(1, (int) round(imagesx($i) * $height / max(1, imagesy($i)))), $images);
        $gap = 12;
        $sheet = imagecreatetruecolor(max(1, array_sum($widths) + $gap * max(0, count($images) - 1)), $height);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 255, 255, 255));
        $x = 0;

        foreach ($images as $at => $image) {
            imagecopyresampled($sheet, $image, $x, 0, 0, 0, $widths[$at], $height, imagesx($image), imagesy($image));
            $x += $widths[$at] + $gap;
        }

        return self::jpeg($sheet, 85);
    }

    private static function upright(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private static function fit(GdImage $image, int $side): GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = $side / max($w, $h);

        if ($scale >= 1) {
            return $image;
        }

        return imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC) ?: $image;
    }

    /** @return array{0: float, 1: float} mean brightness and the variance of a Laplacian, on a small grey copy */
    private static function measure(GdImage $image): array
    {
        $small = imagescale($image, 256, -1, IMG_BILINEAR_FIXED) ?: $image;
        [$w, $h] = [imagesx($small), imagesy($small)];
        $grey = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey[$y][$x] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        $sum = 0.0;
        $values = [];
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                $sum += $grey[$y][$x];
                $values[] = 4 * $grey[$y][$x] - $grey[$y - 1][$x] - $grey[$y + 1][$x] - $grey[$y][$x - 1] - $grey[$y][$x + 1];
            }
        }

        $n = max(1, count($values));
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn (float $v): float => ($v - $mean) ** 2, $values)) / $n;

        return [$sum / $n, $variance];
    }

    private static function jpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
