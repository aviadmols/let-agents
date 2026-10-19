<?php

namespace App\Modules\Shortlist\Actions;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * How a product card looks in the shop's theme, read from one of its own category pages, in code: no
 * model. The page is fetched from the shop's own address only (and never from an address inside a
 * private network). Every link to a product is followed up to the largest box around it that holds no
 * other product: that box is the card. The cards that sit side by side in the same list are the
 * product grid, and what they have in common becomes a CSS selector the heart script uses on every
 * category page (shortlist.card_selector).
 */
final class DetectProductCards
{
    public const MAX_BYTES = 3_000_000;

    /** Classes that say nothing about what a card is: its state, its category, its place in a row. */
    private const VOLATILE = '/^(first|last|odd|even|instock|outofstock|onbackorder|sale|featured|purchasable|taxable|shipping-taxable|has-post-thumbnail|sold-out|on-sale|active|hidden|visible|swiper-slide-.*|is-.*|has-.*|post-.*|product_cat-.*|product_tag-.*|product-type-.*|pa_.*|status-.*|type-.*|col-.*|.*--.*|.*__.*--.*)$/i';

    private const PRICE = '/price|money|amount/i';

    /**
     * @param  list<string>  $hosts  the shop's own hosts, lower case, without www.
     * @return array{selector: string, count: int, sample: array{title: ?string, image: ?string}}|array{error: string}
     */
    public function fromUrl(string $url, array $hosts, bool $shopify): array
    {
        $url = trim($url);
        if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://'.ltrim($url, '/');
        }

        $host = self::host($url);
        if ($host === null || ! in_array($host, $hosts, true)) {
            return ['error' => 'other_site'];
        }

        if (self::privateAddress((string) parse_url($url, PHP_URL_HOST))) {
            return ['error' => 'unreachable'];
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'LetAgents-CardFinder/1.0', 'Accept' => 'text/html'])
                ->timeout(12)
                ->withOptions(['allow_redirects' => ['max' => 3, 'on_redirect' => function ($request, $response, UriInterface $uri) use ($hosts): void {
                    // A redirect stays on the shop's own address.
                    if (! in_array(self::host((string) $uri), $hosts, true) || self::privateAddress($uri->getHost())) {
                        throw new RuntimeException('other_site');
                    }
                }]])
                ->get($url);
        } catch (Throwable) {
            return ['error' => 'unreachable'];
        }

        if (! $response->successful()) {
            return ['error' => 'unreachable'];
        }

        $found = $this->inHtml(substr($response->body(), 0, self::MAX_BYTES), $url, $shopify);

        return $found ?? ['error' => 'no_cards'];
    }

    /** @return array{selector: string, count: int, sample: array{title: ?string, image: ?string}}|null */
    public function inHtml(string $html, string $pageUrl, bool $shopify): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        // The theme's own links to products first; a theme with other product addresses: any priced card with a picture.
        foreach ([true, false] as $byAddress) {
            $found = $this->find($xpath, $shopify, $byAddress, $pageUrl);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array{selector: string, count: int, sample: array{title: ?string, image: ?string}}|null */
    private function find(DOMXPath $xpath, bool $shopify, bool $byAddress, string $pageUrl): ?array
    {
        $links = [];
        foreach ($xpath->query('//body//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement || $this->outsideTheList($a)) {
                continue;
            }
            $key = $this->productKey($a->getAttribute('href'), $shopify, $byAddress, $a);
            if ($key !== null) {
                $links[] = [$a, $key];
            }
        }

        // Each link up to the largest box around it with no other product in it.
        $cards = [];
        foreach ($links as [$a, $key]) {
            $card = $a;
            for ($node = $a->parentNode, $depth = 0; $node instanceof DOMElement && ! in_array($node->tagName, ['body', 'html', 'main'], true) && $depth < 12; $node = $node->parentNode, $depth++) {
                if ($this->otherProductInside($xpath, $node, $key, $shopify, $byAddress)) {
                    break;
                }
                $card = $node;
            }

            if ($card === $a || ! $this->hasPicture($xpath, $card) || (! $byAddress && ! $this->hasPrice($xpath, $card))) {
                continue;
            }

            $cards[$card->getNodePath()] = [$card, $this->namedInside($xpath, $card)];
        }

        // The cards side by side in one list are the grid: the largest such group wins. A card is named
        // by the box inside it that says it is one (a column holding a "product-card").
        $groups = [];
        foreach ($cards as [$outer, $named]) {
            $parent = $outer->parentNode;
            $groups[($parent instanceof DOMNode ? $parent->getNodePath() : '').'/'.$outer->tagName.'/'.$named->tagName][] = $named;
        }
        uasort($groups, fn (array $a, array $b): int => count($b) <=> count($a));
        $grid = reset($groups) ?: [];

        if (count($grid) < 2) {
            return null;
        }

        $rule = $this->selector($xpath, $grid);
        if ($rule === null) {
            return null;
        }

        [$selector, $query] = $rule;
        $count = 0;
        foreach ($xpath->query($query) ?: [] as $match) {
            foreach ($links as [$a]) {
                if ($match === $a || ($match instanceof DOMElement && $this->contains($match, $a))) {
                    $count++;
                    break;
                }
            }
        }

        return ['selector' => $selector, 'count' => max($count, count($grid)), 'sample' => $this->sample($xpath, $grid[0], $pageUrl)];
    }

    /**
     * What all the cards of the grid share, as a selector the browser understands and the same as XPath
     * to count it here: tag.class, tag[data-product-id], or .list > tag.
     *
     * @param  list<DOMElement>  $grid
     * @return array{0: string, 1: string}|null
     */
    private function selector(DOMXPath $xpath, array $grid): ?array
    {
        $tag = strtolower($grid[0]->tagName);
        if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1) {
            return null;
        }

        $common = null;
        foreach ($grid as $card) {
            $classes = self::classes($card);
            $common = $common === null ? $classes : array_values(array_intersect($common, $classes));
        }
        $common = $common ?? [];

        // The most telling class first: "product", then a product word, then a card, then an item.
        usort($common, fn (string $a, string $b): int => [self::rank($a), strlen($a)] <=> [self::rank($b), strlen($b)]);

        $chosen = [];
        foreach ($common as $class) {
            $chosen[] = $class;
            $query = '//'.$tag.implode('', array_map(fn (string $c): string => self::hasClass($c), $chosen));
            // Specific enough: what it finds is about the grid, not every box on the page.
            if (($xpath->query($query)?->length ?? 0) <= max(count($grid) * 2, count($grid) + 4) || count($chosen) === 3) {
                return [$tag.implode('', array_map(fn (string $c): string => '.'.$c, $chosen)), $query];
            }
        }

        foreach (['data-product-id', 'data-product_id', 'data-product-handle', 'data-product'] as $attribute) {
            if (array_filter($grid, fn (DOMElement $card): bool => ! $card->hasAttribute($attribute)) === []) {
                return ["{$tag}[{$attribute}]", "//{$tag}[@{$attribute}]"];
            }
        }

        $parent = $grid[0]->parentNode;
        $parentClasses = $parent instanceof DOMElement ? self::classes($parent) : [];
        usort($parentClasses, fn (string $a, string $b): int => [self::rank($a), strlen($a)] <=> [self::rank($b), strlen($b)]);
        if ($parentClasses !== []) {
            return ['.'.$parentClasses[0].' > '.$tag, '//*'.self::hasClass($parentClasses[0]).'/'.$tag];
        }

        return null;
    }

    /** The product a link points to, or null: Shopify /products/{handle}, WooCommerce /product/{slug}, else a priced picture link. */
    private function productKey(string $href, bool $shopify, bool $byAddress, DOMElement $a): ?string
    {
        $path = rtrim((string) parse_url(html_entity_decode($href), PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        if ($byAddress) {
            $pattern = $shopify ? '#/products/([^/]+)$#' : '#/product/([^/]+)$#';

            return preg_match($pattern, $path, $m) === 1 ? strtolower(rawurldecode($m[1])) : null;
        }

        // Without a known address: a link around a picture names a product by its path.
        return $a->getElementsByTagName('img')->length > 0 && substr_count($path, '/') >= 1 ? strtolower(rawurldecode($path)) : null;
    }

    private function otherProductInside(DOMXPath $xpath, DOMElement $node, string $key, bool $shopify, bool $byAddress): bool
    {
        foreach ($xpath->query('.//a[@href]', $node) ?: [] as $a) {
            if ($a instanceof DOMElement) {
                $other = $this->productKey($a->getAttribute('href'), $shopify, $byAddress, $a);
                if ($other !== null && $other !== $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A box with one child that is plainly the card ("product-card" inside a grid column) is named by that child. */
    private function namedInside(DOMXPath $xpath, DOMElement $card): DOMElement
    {
        if (self::rank(implode(' ', self::classes($card))) < 3) {
            return $card;
        }

        $node = $card;
        for ($depth = 0; $depth < 4; $depth++) {
            if (self::rank(implode(' ', self::classes($node))) < 3 && $node !== $card) {
                return $node;
            }
            $children = array_values(array_filter(iterator_to_array($node->childNodes), fn ($c): bool => $c instanceof DOMElement));
            if (count($children) !== 1 || $children[0]->tagName === 'a' || ! $this->hasPicture($xpath, $children[0])) {
                break;
            }
            $node = $children[0];
        }

        return $card;
    }

    private function hasPicture(DOMXPath $xpath, DOMElement $node): bool
    {
        return ($xpath->query('.//img | .//picture', $node)?->length ?? 0) > 0;
    }

    private function hasPrice(DOMXPath $xpath, DOMElement $node): bool
    {
        foreach ($xpath->query('.//*[@class]', $node) ?: [] as $el) {
            if ($el instanceof DOMElement && preg_match(self::PRICE, $el->getAttribute('class')) === 1) {
                return true;
            }
        }

        return preg_match('/(₪|\$|€|£|ש"ח)\s*\d|\d\s*(₪|\$|€|£|ש"ח)/u', (string) $node->textContent) === 1;
    }

    /** Menus, headers and footers hold product pictures too, but no grid. */
    private function outsideTheList(DOMElement $a): bool
    {
        for ($node = $a->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array($node->tagName, ['header', 'nav', 'footer'], true)) {
                return true;
            }
        }

        return false;
    }

    private function contains(DOMElement $outer, DOMNode $inner): bool
    {
        for ($node = $inner->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node === $outer) {
                return true;
            }
        }

        return false;
    }

    /** @return array{title: ?string, image: ?string} the first card, for the screen to show */
    private function sample(DOMXPath $xpath, DOMElement $card, string $pageUrl): array
    {
        $title = null;
        foreach (['.//h1 | .//h2 | .//h3 | .//h4', './/*[contains(@class, "title") or contains(@class, "name")]', './/a', '.'] as $query) {
            foreach ($xpath->query($query, $card) ?: [] as $el) {
                $text = trim((string) preg_replace('/\s+/u', ' ', (string) $el->textContent));
                if ($text !== '') {
                    $title = mb_substr($text, 0, 80);
                    break 2;
                }
            }
        }

        $image = null;
        $img = $xpath->query('.//img', $card)?->item(0);
        if ($img instanceof DOMElement) {
            foreach (['src', 'data-src', 'data-lazy-src', 'srcset', 'data-srcset'] as $attribute) {
                $value = trim(explode(' ', trim($img->getAttribute($attribute)))[0] ?? '');
                if ($value !== '' && ! str_starts_with($value, 'data:')) {
                    $image = self::absolute($value, $pageUrl);
                    break;
                }
            }
        }

        return ['title' => $title, 'image' => $image];
    }

    /** @return list<string> the card's classes that say what it is */
    private static function classes(DOMElement $node): array
    {
        $tokens = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

        return array_values(array_unique(array_filter($tokens, fn (string $c): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $c) === 1
            && preg_match('/\d/', $c) !== 1 && preg_match(self::VOLATILE, $c) !== 1)));
    }

    private static function rank(string $class): int
    {
        return match (true) {
            preg_match('/(^|\s)product(\s|$)/', $class) === 1 => 0,
            str_contains($class, 'product') => 1,
            str_contains($class, 'card') => 2,
            str_contains($class, 'item') => 3,
            default => 4,
        };
    }

    private static function hasClass(string $class): string
    {
        return "[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
    }

    private static function absolute(string $src, string $pageUrl): string
    {
        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        $origin = parse_url($pageUrl, PHP_URL_SCHEME).'://'.parse_url($pageUrl, PHP_URL_HOST);

        return $origin.'/'.ltrim($src, '/');
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower((string) preg_replace('/^www\./i', '', $host)) : null;
    }

    /** An address that resolves inside a private or reserved network is never fetched. */
    private static function privateAddress(string $host): bool
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }
}
