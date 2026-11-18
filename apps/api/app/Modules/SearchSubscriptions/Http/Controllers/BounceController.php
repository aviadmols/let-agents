<?php

namespace App\Modules\SearchSubscriptions\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/search-subscriptions/bounces: Resend's webhook (signed the Svix way, services.resend.webhook_secret).
 * A hard bounce (email.bounced: the receiving server refused the address for good) or a complaint (email.complained:
 * marked as spam) stops every search of that address, in every store: nothing more is sent to it. Anything else is
 * acknowledged and ignored. Without a secret, or with a wrong signature or an old timestamp, nothing is read.
 */
final class BounceController
{
    /** How old a signed delivery may be, in seconds (Svix's own tolerance). */
    private const TOLERANCE = 300;

    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $body = (string) $request->getContent();
        if (! self::signed($body, (string) $request->header('svix-id'), (string) $request->header('svix-timestamp'), (string) $request->header('svix-signature'))) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $event = json_decode($body, true);
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        $reason = ['email.bounced' => 'bounced', 'email.complained' => 'complained'][$type] ?? null;
        if ($reason === null) {
            return response()->json(['status' => 'ignored']);
        }

        $stopped = 0;
        foreach ((array) ($event['data']['to'] ?? []) as $address) {
            if (! is_string($address) || ! Subscribers::validEmail($address)) {
                continue;
            }
            $hash = Subscribers::hash($address);
            $stopped += $tenant->runUnscoped(function () use ($hash, $reason): int {
                $open = SearchSubscription::query()->where('email_hash', $hash)->where('status', '!=', SearchSubscription::STOPPED)->get();
                $open->each(fn (SearchSubscription $s) => $s->stop($reason));

                return $open->count();
            });
        }

        return response()->json(['status' => 'stopped', 'stopped' => $stopped]);
    }

    /** Svix's signature: base64 HMAC-SHA256 of "id.timestamp.body" with the secret after "whsec_", any of the v1 ones. */
    public static function signed(string $body, string $id, string $timestamp, string $signatures): bool
    {
        $secret = (string) config('services.resend.webhook_secret');
        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false || $key === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }
}
