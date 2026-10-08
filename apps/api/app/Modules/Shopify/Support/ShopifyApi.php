<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Shopify\Models\ShopifyInstall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Shopify Admin GraphQL API for one installed store, with its token kept fresh: an access
 * token about to expire is exchanged for a new one with the refresh token first. Throttled
 * requests wait and try again; a store that removed the app or whose access cannot be renewed
 * is reported as not installed.
 */
final class ShopifyApi
{
    private const TIMEOUT_SECONDS = 30;

    private const ATTEMPTS = 3;

    /** A throttled call waits and tries again this often: a big catalogue reads for minutes. */
    private const THROTTLED_ATTEMPTS = 8;

    /** A token this close to expiring is renewed before use. */
    private const RENEW_BEFORE_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the "data" of the answer
     *
     * @throws StoreFeedUnavailable
     */
    public function query(ShopifyInstall $install, string $query, array $variables = []): array
    {
        $token = $this->token($install);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['X-Shopify-Access-Token' => $token])
                    ->acceptJson()
                    ->post(self::url($install->shop_domain, '/admin/api/'.config('services.shopify.version').'/graphql.json'), [
                        'query' => $query,
                        'variables' => (object) $variables,
                    ]);
            } catch (ConnectionException $e) {
                if ($attempt < self::ATTEMPTS) {
                    usleep(500_000 * $attempt);

                    continue;
                }

                throw new StoreFeedUnavailable('unreachable', null, $e->getMessage());
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new StoreFeedUnavailable('not_installed', $response->status());
            }

            $throttled = $response->status() === 429 || collect((array) $response->json('errors'))->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');

            if ($throttled && $attempt < self::THROTTLED_ATTEMPTS) {
                usleep(self::waitFor((array) $response->json('extensions.cost'), $attempt));

                continue;
            }

            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new StoreFeedUnavailable('unexpected_response', $response->status(), (string) json_encode($response->json('errors')));
            }

            return (array) $response->json('data');
        }
    }

    /** The access token, renewed first when it is about to expire. */
    public function token(ShopifyInstall $install): string
    {
        if (! $install->installed()) {
            throw new StoreFeedUnavailable('not_installed');
        }

        $expires = $install->access_expires_at;

        if ($expires !== null && $expires->lte(now()->addSeconds(self::RENEW_BEFORE_SECONDS))) {
            $this->renew($install);
        }

        return (string) $install->access_token;
    }

    /**
     * The store's offline token, in exchange for the session token an embedded app opens with:
     * no redirect, no screen. Null when Shopify refused or did not answer.
     *
     * @return array<string, mixed>|null the token answer, as the authorization code grant gives it
     */
    public function exchange(string $shopDomain, string $app, string $idToken): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)->post(self::url($shopDomain, '/admin/oauth/access_token'), [
                'client_id' => $app,
                'client_secret' => ShopifyApps::secret($app),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $idToken,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'expiring' => 1,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && is_string($response->json('access_token')) ? (array) $response->json() : null;
    }

    /** Exchanges the refresh token for a new access token (and a new refresh token). */
    public function renew(ShopifyInstall $install): void
    {
        if ($install->refresh_token === null || ($install->refresh_expires_at !== null && $install->refresh_expires_at->isPast())) {
            throw new StoreFeedUnavailable('not_installed');
        }

        $response = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post(self::url($install->shop_domain, '/admin/oauth/access_token'), [
            'client_id' => ShopifyApps::key($install->client_id),
            'client_secret' => ShopifyApps::secret($install->client_id),
            'grant_type' => 'refresh_token',
            'refresh_token' => $install->refresh_token,
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new StoreFeedUnavailable('not_installed', $response->status());
        }

        $install->forceFill(self::tokenFields((array) $response->json()))->save();
    }

    /**
     * The fields a token answer sets on an install, for the first install and every renewal.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    public static function tokenFields(array $answer): array
    {
        return array_filter([
            'access_token' => (string) $answer['access_token'],
            'access_expires_at' => isset($answer['expires_in']) ? now()->addSeconds((int) $answer['expires_in']) : null,
            'refresh_token' => $answer['refresh_token'] ?? null,
            'refresh_expires_at' => isset($answer['refresh_token_expires_in']) ? now()->addSeconds((int) $answer['refresh_token_expires_in']) : null,
            'scopes' => $answer['scope'] ?? null,
        ], fn ($v): bool => $v !== null);
    }

    /**
     * How long to wait after Shopify throttled a call: the points the query needs, minus those
     * left, at the rate they come back. Shopify sends all three; without them, back off.
     *
     * @param  array<string, mixed>  $cost
     */
    private static function waitFor(array $cost, int $attempt): int
    {
        $needed = (float) ($cost['requestedQueryCost'] ?? 0);
        $left = (float) ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $rate = (float) ($cost['throttleStatus']['restoreRate'] ?? 0);
        $seconds = $rate > 0 && $needed > $left ? ($needed - $left) / $rate + 0.5 : $attempt;

        return (int) (min($seconds, 20) * 1_000_000);
    }

    public static function url(string $shopDomain, string $path): string
    {
        return 'https://'.$shopDomain.$path;
    }
}
