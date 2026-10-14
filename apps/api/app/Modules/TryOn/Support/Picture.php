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
