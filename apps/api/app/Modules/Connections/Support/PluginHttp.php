<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;

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
    /**
     * Redirects only within the store's own site (its host, with or without www, on https or http): the token and the
     * signature never follow one to another host, which would then hold the key that signs this store's requests.
     *
     * @return array<string, mixed>
     */
    private static function sameSite(StoreConnection $connection): array
    {
        $home = preg_replace('/^www\./', '', strtolower((string) parse_url((string) $connection->site_url, PHP_URL_HOST)));

        return ['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https'], 'on_redirect' => function ($request, $response, UriInterface $to) use ($home): void {
            if (preg_replace('/^www\./', '', strtolower($to->getHost())) !== $home) {
                throw new \RuntimeException('The store\'s site redirected to another host: the token is not sent there.');
            }
        }]];
    }

    public static function get(StoreConnection $connection, string $route, array $query = [], int $timeoutSeconds = 30): array
    {
        $client = Http::acceptJson()
            ->withOptions(self::sameSite($connection))
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

    /**
     * One POST to the plugin's write route, with the token and a signature over the time, the
     * method, the route and the body (SiteKeys::signature), which the plugin checks.
     *
     * @return array{0: Response, 1: string} the response and the URL that produced it
     */
    public static function post(StoreConnection $connection, string $route, string $body, int $timeoutSeconds = 30): array
    {
        $route = '/'.ltrim($route, '/');
        $timestamp = (string) time();
        $client = Http::acceptJson()
            ->withHeaders([
                'X-LetAgents-Token' => $connection->access_token,
                'X-LetAgents-Timestamp' => $timestamp,
                'X-LetAgents-Signature' => SiteKeys::signature((string) $connection->access_token, $timestamp, 'POST', $route, $body),
                'User-Agent' => 'Let Agents/'.config('app.name'),
            ])
            ->withBody($body, 'application/json')
            ->withOptions(self::sameSite($connection))
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeoutSeconds);

        $url = $connection->site_url.'/wp-json'.$route;
        $response = $client->post($url);

        if ($response->status() === 404 && ! is_string($response->json('code'))) {
            $url = $connection->site_url.'/?rest_route='.$route;
            $response = $client->post($url);
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
