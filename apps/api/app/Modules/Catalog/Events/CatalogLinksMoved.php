<?php

namespace App\Modules\Catalog\Events;

/**
 * A shop's saved links moved from one host to another (the store moved: StoreAddressMoved), before the catalogue is
 * rebuilt for it: what another module kept by a link (a picture's vector, an answer's sources) moves now, so nothing
 * done for the old address is done again. "www." on the old host is the same site; links on other hosts (a CDN) stay.
 */
final class CatalogLinksMoved
{
    /** The old host without "www."; the new host as the store is read at. */
    public function __construct(
        public readonly string $shopId,
        public readonly string $from,
        public readonly string $to,
    ) {}

    /** The link at the new host, or as it was when it is on another. */
    public function link(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($host === '' || self::bare($host) !== $this->from) {
            return $url;
        }

        return (string) preg_replace('~^(\w+://)[^/:?#]+~u', '${1}'.$this->to, (string) $url, 1);
    }

    /** Every link on the old host inside a text (an answer that names a page). */
    public function inText(string $text): string
    {
        return (string) preg_replace('~(https?://)(?:www\.)?'.preg_quote($this->from, '~').'(?=[/:?#\s)\]"\'>]|$)~iu', '${1}'.$this->to, $text);
    }

    public static function bare(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
