<?php

namespace App\Modules\Demo\Support;

use App\Modules\Connections\Contracts\ContentSiteFeed;
use App\Modules\Tenancy\Models\Shop;
use Generator;

/**
 * The one reader of a content site (WordPress with no WooCommerce), for a demo (PublicStoreFeed) and for a site
 * connected with the plugin (Connections' PluginStoreFeed, through ContentSiteFeed): what it offers as products, what
 * it writes as articles, each item once, never its internal pages (WordPressSite). Its search is set as a content
 * site's (ContentSite), its prices in the currency its pages state.
 */
final class WordPressContentFeed implements ContentSiteFeed
{
    /** A pause between items: a site is never weighed on. */
    private const PAUSE_MS = 400;

    public function categories(string $siteUrl): array
    {
        $host = self::host($siteUrl);

        return WordPressSite::categories($host, array_keys(self::types($host, WordPressSite::OFFERING)));
    }

    public function products(string $siteUrl): Generator
    {
        $host = self::host($siteUrl);
        $template = WordPressSite::read($host)['template'];
        $seen = [];
        foreach (self::types($host, WordPressSite::OFFERING) as $type) {
            foreach (WordPressSite::items($host, $type['rest']) as $items) {
                $batch = [];
                foreach ($items as $item) {
                    // one item once: a type whose items link to another's pages (a news ticker pointing at the tours) adds none again
                    $address = rtrim(strtolower((string) preg_replace('~^https?://(www\.)?|[?#].*$~i', '', (string) ($item['link'] ?? ''))), '/');
                    if ($address === '' || isset($seen[$address])) {
                        continue;
                    }
                    $seen[$address] = true;
                    $batch[] = WordPressSite::product($host, $item, $type['name'], $template);
                    if (! app()->runningUnitTests()) {
                        usleep(self::PAUSE_MS * 1000);
                    }
                }
                if ($batch !== []) {
                    yield $batch;
                }
            }
        }
    }

    public function contentTypes(string $siteUrl): array
    {
        return self::types(self::host($siteUrl), WordPressSite::ARTICLE) === [] ? [] : ['post'];
    }

    public function content(string $siteUrl, string $type): Generator
    {
        $host = self::host($siteUrl);
        $template = WordPressSite::read($host)['template'];
        foreach ($type === 'post' ? self::types($host, WordPressSite::ARTICLE) : [] as $written) {
            foreach (WordPressSite::items($host, $written['rest']) as $items) {
                yield array_map(fn (array $item): array => WordPressSite::article($host, $item, $template), $items);
            }
        }
    }

    public function settle(string $shopId, string $siteUrl): void
    {
        ContentSite::settle($shopId);
        $currency = WordPressSite::read(self::host($siteUrl))['currency'] ?? null;
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            Shop::query()->whereKey($shopId)->update(['currency' => $currency]);
        }
    }

    /**
     * A content site's types of one role (WordPressSite::OFFERING or ARTICLE), by slug.
     *
     * @return array<string, array{rest: string, name: string, role: string, total: int}>
     */
    private static function types(string $host, string $role): array
    {
        return array_filter(WordPressSite::read($host)['types'], fn (array $type): bool => $type['role'] === $role);
    }

    private static function host(string $siteUrl): string
    {
        return (string) PublicSite::host($siteUrl);
    }
}
