<?php

namespace App\Modules\Demo\Support;

/**
 * The addresses a store's sitemap lists, through its sitemap index, for a site with no public product feed: the
 * product pages, by the word the platform puts in their address ("/product", "/products/", "/p/"). Code only.
 */
final class Sitemap
{
    /** Sitemap files read at most, the index's included. */
    private const FILES = 12;

    /**
     * The WordPress content types a site lists for search engines, by its sitemap files' names: Yoast and Rank Math's
     * "tour-sitemap.xml", WordPress's own "wp-sitemap-posts-tour-1.xml". Null when the site has no sitemap index.
     *
     * @return list<string>|null
     */
    public static function types(string $host): ?array
    {
        $types = [];
        $found = false;
        foreach (['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml'] as $file) {
            $xml = (string) PublicSite::get($host, $file)?->body();
            if (stripos($xml, '<sitemapindex') === false) {
                continue;
            }
            $found = true;
            preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m);
            foreach ($m[1] as $loc) {
                $name = (string) basename((string) parse_url(html_entity_decode($loc), PHP_URL_PATH));
                if (preg_match('~^wp-sitemap-posts-([a-z0-9_-]+)-\d+\.xml$~i', $name, $t) === 1 || preg_match('~^([a-z0-9_-]+?)-sitemap\d*\.xml$~i', $name, $t) === 1) {
                    $types[strtolower($t[1])] = true;
                }
            }
        }

        return $found ? array_keys($types) : null;
    }

    /** @return list<string> */
    public static function urls(string $host, string $of = 'product'): array
    {
        $queue = ['/sitemap.xml', '/sitemap_index.xml'];
        $seen = [];
        $urls = [];

        while ($queue !== [] && count($seen) < self::FILES) {
            $file = array_shift($queue);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $xml = (string) PublicSite::get($host, $file)?->body();
            preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m);

            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc);
                if (preg_match('~\.xml(\?|$)~i', $loc) === 1) {
                    // a product sitemap first: the rest only when there is room
                    str_contains(strtolower($loc), $of) ? array_unshift($queue, $loc) : $queue[] = $loc;
                } elseif ($of !== 'product' || preg_match('~/(products?|p|item)/~i', $loc) === 1) {
                    $urls[$loc] = true;
                }
            }
        }

        return array_keys($urls);
    }
}
