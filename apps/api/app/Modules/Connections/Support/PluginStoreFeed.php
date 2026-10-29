<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\ContentSiteFeed;
use App\Modules\Connections\Contracts\PlatformStoreFeed;
use App\Modules\Connections\Contracts\ReadsChangedProducts;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\ConnectionException;

/**
 * The feed as the WooCommerce Let Agents plugin serves it: /let-agents/v1/feed/*, paged by ascending ID
 * with an "after" cursor.
 */
final class PluginStoreFeed implements PlatformStoreFeed, ReadsChangedProducts
{
    public function platform(): string
    {
        return 'woocommerce';
    }

    private const PER_PAGE = 50;

    /** Seconds between two pages of the feed. */
    private const BETWEEN_PAGES_SECONDS = 1;

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

    /**
     * A site whose plugin says WooCommerce is not active is a content site: its catalogue is read by the one content
     * site reader (ContentSiteFeed), as a demo of it is. A store with WooCommerce never reaches it; nor does one whose
     * plugin has not said (an older plugin, a status not read yet).
     */
    private function contentSite(StoreConnection $connection): ?ContentSiteFeed
    {
        return $connection->info('woocommerce.active') === false && app()->bound(ContentSiteFeed::class) ? app(ContentSiteFeed::class) : null;
    }

    public function categories(StoreConnection $connection): array
    {
        if ($site = $this->contentSite($connection)) {
            return $site->categories((string) $connection->site_url);
        }

        return array_values((array) $this->get($connection, '/feed/categories')['data']);
    }

    public function products(StoreConnection $connection): Generator
    {
        if ($site = $this->contentSite($connection)) {
            yield from $site->products((string) $connection->site_url);

            return;
        }

        foreach ($this->pages($connection, '/feed/products') as $records) {
            yield array_values(array_filter($records, fn ($r): bool => ! is_array($r) || ! self::hidden($r)));
        }
    }

    /**
     * A product the store hides from its catalogue (WooCommerce's catalog visibility "hidden": on no shop page and in
     * no search) is not read, as a draft is not: it leaves the catalogue on the next read (Aviad, 29.10). One shown
     * on shop pages only, or in the store's own search only, is still the store's to be found by.
     *
     * @param  array<string, mixed>  $record
     */
    private static function hidden(array $record): bool
    {
        return ($record['catalog_visibility'] ?? 'visible') === 'hidden';
    }

    public function changedProducts(StoreConnection $connection, CarbonInterface $since): ?Generator
    {
        // a content site has no quick read of what changed: its nightly read sees it
        if ($this->contentSite($connection)) {
            return null;
        }

        return $this->changed($connection, $since);
    }

    /**
     * What the store changed since a moment, in every status: a published product is read again,
     * one made a draft, pending or private, or hidden from the catalogue, has gone. A product deleted outright leaves no trace and
     * is the nightly read's. A stock change from an order saves the product, so it is seen too.
     *
     * @return Generator<int, array{records: list<array<string, mixed>>, gone: list<string>}>
     */
    private function changed(StoreConnection $connection, CarbonInterface $since): Generator
    {
        foreach ($this->pages($connection, '/feed/products', ['since' => $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => 'any']) as $records) {
            $records = array_values(array_filter($records, fn ($r): bool => is_array($r) && isset($r['external_id'])));

            yield [
                'records' => array_values(array_filter($records, fn (array $r): bool => ($r['status'] ?? 'publish') === 'publish' && ! self::hidden($r))),
                'gone' => array_values(array_map(fn (array $r): string => (string) $r['external_id'], array_filter($records, fn (array $r): bool => ($r['status'] ?? 'publish') !== 'publish' || self::hidden($r)))),
            ];
        }
    }

    public function content(StoreConnection $connection, string $type): Generator
    {
        if ($site = $this->contentSite($connection)) {
            yield from $site->content((string) $connection->site_url, $type);

            return;
        }

        yield from $this->pages($connection, '/feed/content', ['type' => $type]);
    }

    public function contentTypes(StoreConnection $connection): array
    {
        if ($site = $this->contentSite($connection)) {
            return $site->contentTypes((string) $connection->site_url);
        }

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
            // A breath between pages: the store's own server builds each one, and it is a shop's site first.
            if ($page > 0) {
                ($this->sleeper)(self::BETWEEN_PAGES_SECONDS);
            }

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
