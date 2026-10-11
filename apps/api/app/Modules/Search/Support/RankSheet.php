<?php

namespace App\Modules\Search\Support;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One picture for the model that orders a photo search's products: the shopper's photo at the top
 * (cut around the object when the reader placed it), the candidates' pictures below it, each with
 * its number. One image, so one call compares them all. Built in code with GD.
 */
final class RankSheet
{
    private const WIDTH = 800;

    private const COLUMNS = 4;

    private const PHOTO_HEIGHT = 400;

    private const TILE = 200;

    private const MARGIN = 0.15;

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $box  where the object is in the photo
     * @param  list<array{external_id: string, image?: string|null}>  $candidates
     * @return array{jpeg: string, numbered: list<string>}|null the sheet and the external ids in their numbers' order; null when it cannot be drawn
     */
    public static function build(string $photo, ?array $box, array $candidates): ?array
    {
        $source = @imagecreatefromstring($photo);

        if ($source === false) {
            return null;
        }

        $pictures = self::pictures($candidates);

        if (count($pictures) < 2) {
            return null;
        }

        $rows = (int) ceil(count($pictures) / self::COLUMNS);
        $sheet = imagecreatetruecolor(self::WIDTH, self::PHOTO_HEIGHT + $rows * self::TILE);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 255, 255, 255));

        // The shopper's photo, or the object cut from it, fitted into the top band.
        [$x, $y, $w, $h] = [0, 0, imagesx($source), imagesy($source)];

        if ($box !== null) {
            [$left, $top, $right, $bottom] = $box;
            $padX = ($right - $left) * self::MARGIN;
            $padY = ($bottom - $top) * self::MARGIN;
            $x = (int) max(0, floor(($left - $padX) * $w));
            $y = (int) max(0, floor(($top - $padY) * $h));
            [$w, $h] = [(int) min($w - $x, ceil(($right - $left + 2 * $padX) * $w)), (int) min($h - $y, ceil(($bottom - $top + 2 * $padY) * $h))];
        }

        self::fit($sheet, $source, $x, $y, $w, $h, 0, 0, self::WIDTH, self::PHOTO_HEIGHT);
        imageline($sheet, 0, self::PHOTO_HEIGHT - 1, self::WIDTH, self::PHOTO_HEIGHT - 1, imagecolorallocate($sheet, 180, 180, 180));

        $numbered = [];
        $black = imagecolorallocate($sheet, 0, 0, 0);
        $white = imagecolorallocate($sheet, 255, 255, 255);

        foreach (array_values($pictures) as $at => [$id, $image]) {
            $left = ($at % self::COLUMNS) * self::TILE;
            $top = self::PHOTO_HEIGHT + intdiv($at, self::COLUMNS) * self::TILE;
            self::fit($sheet, $image, 0, 0, imagesx($image), imagesy($image), $left + 6, $top + 6, self::TILE - 12, self::TILE - 12);
            imagefilledrectangle($sheet, $left + 4, $top + 4, $left + 40, $top + 30, $black);
            imagestring($sheet, 5, $left + 12, $top + 9, (string) ($at + 1), $white);
            $numbered[] = $id;
        }

        ob_start();
        imagejpeg($sheet, null, 85);

        return ['jpeg' => (string) ob_get_clean(), 'numbered' => $numbered];
    }

    /**
     * The candidates' pictures, fetched side by side and small; a picture that does not come is left out.
     *
     * @param  list<array{external_id: string, image?: string|null}>  $candidates
     * @return list<array{0: string, 1: \GdImage}>
     */
    private static function pictures(array $candidates): array
    {
        $wanted = array_values(array_filter($candidates, fn (array $c): bool => is_string($c['image'] ?? null) && preg_match('#^https?://#i', (string) $c['image']) === 1));

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $c) => $pool->as((string) $c['external_id'])->timeout(6)->connectTimeout(3)->get(self::small((string) $c['image'])),
                $wanted,
            ));
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($wanted as $c) {
            $response = $responses[(string) $c['external_id']] ?? null;

            if ($response instanceof Response && $response->successful() && ($image = @imagecreatefromstring($response->body())) !== false) {
                $out[] = [(string) $c['external_id'], $image];
            }
        }

        return $out;
    }

    /** A store picture asked for small where the store's server can make it so (Shopify's can). */
    private static function small(string $url): string
    {
        if (! str_contains($url, 'cdn.shopify.com') && ! str_contains($url, '/cdn/shop/')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'width=320';
    }

    /** $source's region drawn into $sheet's box, whole, centred, keeping its proportions. */
    private static function fit(\GdImage $sheet, \GdImage $source, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh): void
    {
        if ($sw < 1 || $sh < 1) {
            return;
        }

        $scale = min($dw / $sw, $dh / $sh);
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        imagecopyresampled($sheet, $source, $dx + intdiv($dw - $w, 2), $dy + intdiv($dh - $h, 2), $sx, $sy, $w, $h, $sw, $sh);
    }
}
