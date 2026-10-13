<?php

namespace App\Modules\Sms\Contracts;

/**
 * An Israeli mobile number as the platform keeps it: 05XXXXXXXX, from "050-123-4567",
 * "+972 50 1234567" or "972501234567". Anything else is not a number we text.
 */
final class Phone
{
    public static function normalize(string $typed): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $typed);

        if (str_starts_with($digits, '972')) {
            $digits = '0'.substr($digits, 3);
        }

        return preg_match('/^05\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /** "050-•••-4567": enough to recognize it, not to call it. */
    public static function masked(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) < 7 ? '•••' : substr($digits, 0, 3).'-•••-'.substr($digits, -4);
    }

    /** The number as a key: the same for the same number, saying nothing of it. */
    public static function key(string $phone): string
    {
        return hash_hmac('sha256', $phone, (string) config('app.key'));
    }
}
