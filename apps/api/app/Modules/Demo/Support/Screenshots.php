<?php

namespace App\Modules\Demo\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The store's home page as pictures, for the demo's background: a computer's width and a phone's, the whole page
 * down to a height. Taken by Microlink, which is given the store's public address and nothing else (no browser runs
 * on our servers); with a key (services.microlink.key) on its paid service, without one on its free one. Kept as
 * JPEG, scaled, base64: the container's disk does not outlast a deploy. Null when no picture came.
 */
final class Screenshots
{
    private const FREE = 'https://api.microlink.io/';

    private const PAID = 'https://pro.microlink.io/';

    /** The tallest picture kept: a long home page is cut there. */
    private const MAX_HEIGHT = 5200;

    /** @return array{desktop: string|null, mobile: string|null} */
    public static function take(string $url): array
    {
        return [
            'desktop' => self::shot($url, ['viewport.width' => 1366, 'viewport.height' => 860], 1366),
            'mobile' => self::shot($url, ['viewport.width' => 390, 'viewport.height' => 844, 'viewport.isMobile' => 'true', 'viewport.deviceScaleFactor' => 2], 780),
        ];
    }

    /** @param array<string, scalar> $viewport */
    private static function shot(string $url, array $viewport, int $width): ?string
    {
        $key = (string) config('services.microlink.key');

        try {
            $answer = Http::timeout(60)->withHeaders($key !== '' ? ['x-api-key' => $key] : [])
                ->get($key !== '' ? self::PAID : self::FREE, ['url' => $url, 'screenshot' => 'true', 'meta' => 'false', 'screenshot.fullPage' => 'true', 'waitForTimeout' => 1500, 'type' => 'jpeg'] + $viewport);
            $picture = (string) $answer->json('data.screenshot.url');

            if (! $answer->successful() || $picture === '') {
                return null;
            }

            $bytes = Http::timeout(60)->get($picture)->body();
        } catch (ConnectionException) {
            return null;
        }

        return self::jpeg($bytes, $width);
    }

    /** The picture as JPEG at most $width wide and MAX_HEIGHT high, base64; null when it is no picture. */
    private static function jpeg(string $bytes, int $width): ?string
    {
        $image = $bytes !== '' ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            return null;
        }

        $scale = min(1, $width / max(1, imagesx($image)));
        $w = (int) round(imagesx($image) * $scale);
        $h = (int) min(self::MAX_HEIGHT, round(imagesy($image) * $scale));
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, (int) round(imagesy($image) * $scale), imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($out, null, 72);

        return base64_encode((string) ob_get_clean());
    }
}
