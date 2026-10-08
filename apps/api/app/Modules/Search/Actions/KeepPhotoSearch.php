<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Support\PhotoLook;
use Throwable;

/**
 * Keeps one search by photo for the team to check: a reduced copy of the photo, what the reader
 * saw and how sure it was, the tags the shopper got and the first results. The copy is kept
 * search.photo_keep_days, unless the team marks the row right or wrong; then it stays, as part
 * of the set every change to photo search is measured against. Nothing about who searched.
 * Keeping never fails the search.
 */
final class KeepPhotoSearch
{
    /** The long side of the kept copy, enough to read again, and of the small one for the list. */
    private const PHOTO_SIDE = 1024;

    private const THUMB_SIDE = 320;

    private const RESULTS = 8;

    /**
     * @param  list<array<string, mixed>>  $products  what the shopper got, first first
     */
    public function handle(string $shopId, string $bytes, PhotoLook $look, array $products, int $total): void
    {
        if (! Features::enabled('search.photo_log', $shopId)) {
            return;
        }

        try {
            SearchPhotoAsk::query()->create([
                'shop_id' => $shopId,
                'photo_hash' => hash('sha256', $bytes),
                'photo' => self::reduced($bytes, self::PHOTO_SIDE, 82),
                'thumb' => self::reduced($bytes, self::THUMB_SIDE, 70),
                'object' => $look->object === null ? null : mb_substr($look->object, 0, 120),
                'sure' => $look->sure,
                'doubt' => $look->doubt,
                'second' => $look->second,
                'main' => $look->main === null ? null : mb_substr($look->main, 0, 200),
                'tags' => array_map(fn (array $t): array => ['title' => (string) $t['title'], 'kind' => (string) $t['kind']], $look->tags ?? []),
                'results' => array_map(fn (array $p): array => [
                    'external_id' => (string) $p['external_id'],
                    'title' => mb_substr((string) $p['title'], 0, 120),
                    'image' => isset($p['image']) ? (string) $p['image'] : null,
                ], array_slice($products, 0, self::RESULTS)),
                'total' => $total,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The photo as a JPEG no longer than $side on its long side, turned the way it was taken,
     * base64. Null when the server cannot read pictures or the bytes are not one.
     */
    public static function reduced(string $bytes, int $side, int $quality): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $image = self::upright($image, $bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $side / max($width, $height, 1));

        if ($scale < 1) {
            $small = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            if ($small !== false) {
                imagedestroy($image);
                $image = $small;
            }
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return $out === '' ? null : base64_encode($out);
    }

    /** A phone photo carries how it was held; without turning it the copy lies on its side. */
    private static function upright(\GdImage $image, string $bytes): \GdImage
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

        if ($angle === 0) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);

        if ($turned === false) {
            return $image;
        }

        imagedestroy($image);

        return $turned;
    }
}
