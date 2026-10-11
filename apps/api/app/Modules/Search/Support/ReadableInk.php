<?php

namespace App\Modules\Search\Support;

/** White or black, whichever reads on a button colour: the same rule the store's script uses. */
final class ReadableInk
{
    public static function on(string $hex): string
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) !== 1) {
            return '#fff';
        }

        $luminance = (0.299 * hexdec($m[1]) + 0.587 * hexdec($m[2]) + 0.114 * hexdec($m[3])) / 255;

        return $luminance > 0.6 ? '#111' : '#fff';
    }
}
