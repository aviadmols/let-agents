<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Visitors who keep trying to break the rules (instructions to the assistant, markup, floods,
 * harmful requests) are blocked from asking for a while. A visitor is the id their browser keeps;
 * after assistant.block_after_violations in a day they are blocked for assistant.block_hours.
 *
 * A network address is many people (an office, a phone carrier, a family), so it is blocked only
 * far later, after assistant.block_network_after_violations from it in a day: the visitor's own
 * block does the work, the address is the floor under a bot that makes up a new id each time.
 * Both are hashes salted per shop, never the address itself.
 */
final class Offenders
{
    private const DAY = 86400;

    public static function blocked(string $shopId, string $visitorHash, ?string $ip): bool
    {
        foreach (self::keys($shopId, $visitorHash, $ip) as $kind => $key) {
            if (Cache::has('assistant:blocked:'.$key)) {
                return true;
            }
        }

        return false;
    }

    /** One violation, from this visitor and their address; either may now be blocked. */
    public static function record(string $shopId, string $visitorHash, ?string $ip): void
    {
        $limits = [
            'visitor' => (int) Settings::get('assistant.block_after_violations', $shopId),
            'network' => (int) Settings::get('assistant.block_network_after_violations', $shopId),
        ];
        $hours = (int) Settings::get('assistant.block_hours', $shopId);

        foreach (self::keys($shopId, $visitorHash, $ip) as $kind => $key) {
            RateLimiter::hit('assistant:violations:'.$key, self::DAY);

            if ($limits[$kind] > 0 && RateLimiter::attempts('assistant:violations:'.$key) >= $limits[$kind]) {
                Cache::put('assistant:blocked:'.$key, true, now()->addHours($hours));
            }
        }
    }

    /** @return array<string, string> */
    private static function keys(string $shopId, string $visitorHash, ?string $ip): array
    {
        $keys = ['visitor' => 'visitor:'.$shopId.':'.$visitorHash];

        if ($ip !== null && $ip !== '') {
            $keys['network'] = 'network:'.$shopId.':'.hash('sha256', $shopId.'|'.$ip);
        }

        return $keys;
    }
}
