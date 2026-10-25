<?php

namespace App\Modules\Demo\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The store's home page as a phone shows it, for the demo's background (shown in a phone, DemoController::phone).
 * Taken by Microlink, which is given the store's public address and nothing else (no browser runs on our servers);
 * with a key (services.microlink.key) on its paid service, without one on its free one.
 *
 * A tall phone screen, not the "whole page": a whole-page picture of a modern store comes out broken (a menu that
 * slides in from the side caught open, the page shifted sideways, pictures that load on scroll still blank, parts
 * that fade in on scroll never shown). So the page is read at a phone's width only, left to settle (its network
 * quiet, then a few seconds more), scrolled down and back so its pictures load, with what fades in shown as is and
 * nothing sticking out sideways. Kept as JPEG, scaled, base64: the container's disk does not outlast a deploy.
 */
final class Screenshots
{
    private const FREE = 'https://api.microlink.io/';

    private const PAID = 'https://pro.microlink.io/';

    /** A phone's width, and how much of the page the picture holds: a few screens. */
    private const WIDTH = 390;

    private const HEIGHT = 2200;

    /** How long the page is left to settle before its picture, in milliseconds. */
    private const SETTLE_MS = 5000;

    /** What fades or slides in on scroll is shown as it ends; nothing sticks out sideways. */
    private const STYLES = '[data-aos],.aos-init,.wow,.animate__animated,.reveal,[data-animate]{opacity:1!important;transform:none!important;visibility:visible!important}';

    /**
     * Down the page and back, so pictures that load on scroll are loaded; and every video played, muted, so a video at
     * the top of the page (often with no still picture of its own) shows a frame rather than a blank. Its sources'
     * declared types are dropped first: a site that labels a WebM file as MP4 has it skipped by a browser without MP4.
     */
    private const SCRIPTS = 'document.querySelectorAll("video source[type]").forEach(function(s){s.removeAttribute("type")});'
        .'document.querySelectorAll("video").forEach(function(v){v.muted=true;v.playsInline=true;v.load();var p=v.play();if(p&&p.catch){p.catch(function(){})}});';

    /** The picture, JPEG base64; null when none came. */
    public static function take(string $url): ?string
    {
        $key = (string) config('services.microlink.key');

        try {
            $answer = Http::timeout(90)->withHeaders($key !== '' ? ['x-api-key' => $key] : [])
                ->get($key !== '' ? self::PAID : self::FREE, [
                    // force: a fresh picture each time, never one Microlink kept of the page before
                    'url' => $url, 'screenshot' => 'true', 'meta' => 'false', 'type' => 'jpeg', 'force' => 'true',
                    'waitUntil' => 'networkidle2', 'waitForTimeout' => self::SETTLE_MS,
                    'viewport.width' => self::WIDTH, 'viewport.height' => self::HEIGHT, 'viewport.isMobile' => 'true',
                    'viewport.hasTouch' => 'true', 'viewport.deviceScaleFactor' => 2,
                    'styles' => self::STYLES, 'scripts' => self::SCRIPTS,
                ]);
            $picture = (string) $answer->json('data.screenshot.url');

            if (! $answer->successful() || $picture === '') {
                return null;
            }

            $bytes = Http::timeout(60)->get($picture)->body();
        } catch (ConnectionException) {
            return null;
        }

        return self::jpeg($bytes, self::WIDTH * 2);
    }

    /** The picture as JPEG at most $width wide, base64; null when it is no picture. */
    private static function jpeg(string $bytes, int $width): ?string
    {
        $image = $bytes !== '' ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            return null;
        }

        $scale = min(1, $width / max(1, imagesx($image)));
        $w = (int) round(imagesx($image) * $scale);
        $h = (int) round(imagesy($image) * $scale);
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($out, null, 76);

        return base64_encode((string) ob_get_clean());
    }
}
