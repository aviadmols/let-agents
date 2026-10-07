<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One GET to the store's Let Agents plugin, with the saved token.
 *
 * Pretty permalinks first. Sites without them answer only on ?rest_route=, so that is tried
 * when, and only when, the first answer did not come from the WordPress REST API at all.
 */
final class PluginHttp
{
    public const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, scalar>  $query
     * @return array{0: Response, 1: string} the response and the URL that produced it
     */
    public static function get(StoreConnection $connection, string $route, array $query = [], int $timeoutSeconds = 30): array
    {
        $client = Http::acceptJson()
            ->withHeaders(['X-LetAgents-Token' => $connection->access_token, 'User-Agent' => 'Let Agents/'.config('app.name')])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeoutSeconds);

        $route = '/let-agents/v1/'.ltrim($route, '/');
        $url = $connection->site_url.'/wp-json'.$route.self::queryString($query);
        $response = $client->get($url);

        // A JSON "rest_no_route" already means the REST API works and the plugin is missing;
        // asking again would only double the wait.
        if ($response->status() === 404 && ! is_string($response->json('code'))) {
            $url = $connection->site_url.'/?rest_route='.$route.self::queryString($query, '&');
            $response = $client->get($url);
        }

        return [$response, $url];
    }

    /** @param array<string, scalar> $query */
    private static function queryString(array $query, string $prefix = '?'): string
    {
        return $query === [] ? '' : $prefix.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Why a plugin answer is not usable, or null when it is. The codes are shared by the
     * connection test and the feed reader, and each has a translated explanation.
     */
    public static function failure(Response $response): ?string
    {
        $code = (string) $response->json('code', '');

        return match (true) {
            $response->status() === 401 => 'invalid_token',
            $response->status() === 429 => 'locked_out',
            $response->status() === 404 && ! str_starts_with($code, 'let_agents_') => 'plugin_missing',
            $response->status() === 503 && $code === 'let_agents_woocommerce_inactive' => 'woocommerce_inactive',
            ! $response->successful() => 'http_error',
            default => null,
        };
    }
}
