<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use Generator;
use Illuminate\Http\Client\ConnectionException;

/**
 * The feed as the WooCommerce Let Agents plugin serves it: /let-agents/v1/feed/*, paged by ascending ID
 * with an "after" cursor.
 */
final class PluginStoreFeed implements StoreFeed
{
    private const PER_PAGE = 50;

    /** A runaway cursor stops here: 2,000 pages is 100,000 records, far above any cap. */
    private const MAX_PAGES = 2000;

    private const TIMEOUT_SECONDS = 60;

    private const ATTEMPTS = 3;

    /** Seconds to wait before the second and third attempt. */
    private const BACKOFF_SECONDS = [2, 6];

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(?\Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    private function pause(int $attempt): void
    {
        ($this->sleeper)(self::BACKOFF_SECONDS[$attempt - 1] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)]);
    }

    public function categories(StoreConnection $connection): array
    {
        return array_values((array) $this->get($connection, '/feed/categories')['data']);
    }

    public function products(StoreConnection $connection): Generator
    {
        yield from $this->pages($connection, '/feed/products');
    }

    public function content(StoreConnection $connection, string $type): Generator
    {
        yield from $this->pages($connection, '/feed/content', ['type' => $type]);
    }

    public function contentTypes(StoreConnection $connection): array
    {
        $types = $connection->info('plugin.content_post_types');

        if (! is_array($types)) {
            $types = (array) data_get($this->get($connection, '/status'), 'data.plugin.content_post_types', []);
        }

        return array_values(array_filter($types, 'is_string'));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function pages(StoreConnection $connection, string $route, array $query = []): Generator
    {
        $after = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->get($connection, $route, $query + ['per_page' => self::PER_PAGE, 'after' => $after]);
            $records = array_values((array) ($body['data'] ?? []));

            if ($records !== []) {
                yield $records;
            }

            $next = data_get($body, 'meta.next_after');

            if (! is_int($next) || $next <= $after) {
                return;
            }

            $after = $next;
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(StoreConnection $connection, string $route, array $query = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                [$response] = PluginHttp::get($connection, $route, $query, self::TIMEOUT_SECONDS);
            } catch (ConnectionException $e) {
                if ($attempt < self::ATTEMPTS) {
                    $this->pause($attempt);

                    continue;
                }

                throw new StoreFeedUnavailable('unreachable', null, $e->getMessage());
            }

            // Shared hosting answers 502, 503 or 504 now and then under load. A whole sync must
            // not fail on one of those, so the page is asked for again after a short wait.
            if (in_array($response->status(), [502, 503, 504], true) && $response->json('code') === null && $attempt < self::ATTEMPTS) {
                $this->pause($attempt);

                continue;
            }

            break;
        }

        if (($failure = PluginHttp::failure($response)) !== null) {
            throw new StoreFeedUnavailable($failure, $response->status(), (string) $response->json('code', ''));
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('data', $body)) {
            throw new StoreFeedUnavailable('unexpected_response', $response->status());
        }

        return $body;
    }
}
