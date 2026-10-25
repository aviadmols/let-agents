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
