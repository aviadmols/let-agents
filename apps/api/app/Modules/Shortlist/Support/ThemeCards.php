<?php

namespace App\Modules\Shortlist\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Where a theme draws its product cards on a category page, read from the page's HTML by code: the
 * links to the store's own products, each climbed up to the largest element that holds that one
 * product and a picture, and the class those elements share and nothing else on the page has.
 *
 * A selector is kept only when it names at least three elements and every one of them links to
 * exactly one product, so a wrong guess (a model's included) never reaches the store.
 */
final class ThemeCards
{
    public const MIN_CARDS = 3;

    /** Classes a theme adds by state or position, never what a card is. */
    private const PASSING = '/^(?:is-|has-|js-|first|last|odd|even|active|hover|loaded|lazy|animate|aos|swiper-slide-|slick-)|\d{3,}/i';

    /**
     * @param  list<string>  $productPaths  the store's product addresses, as paths (/product/x/)
     * @return array{selector: string, cards: int, products: int}|null
     */
    public static function find(string $html, array $productPaths): ?array
    {
        [$doc, $xpath] = self::parse($html);

        if ($doc === null) {
            return null;
        }

        $known = array_flip(array_map([self::class, 'path'], $productPaths));
        $links = self::productLinks($xpath, $known);

        if (count($links) < self::MIN_CARDS) {
            return null;
        }

        // Each product's card: up from its links while the element holds no other product.
        $cards = [];
        foreach ($links as $key => $anchors) {
            $card = self::card($anchors, $key, $known);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        // The look most cards share.
        $looks = [];
        foreach ($cards as $card) {
            $looks[self::look($card)][] = $card;
        }
        uasort($looks, fn (array $a, array $b): int => count($b) <=> count($a));

        foreach ($looks as $same) {
            if (count($same) < self::MIN_CARDS) {
                break;
            }

            foreach (self::selectors($same) as $selector) {
                $checked = self::check($html, $selector, $productPaths);

                if ($checked !== null && $checked['cards'] >= count($same)) {
                    return $checked;
                }
            }
        }

        return null;
    }

    /**
     * Whether a selector names the product cards of this page: at least three elements, each
     * linking to exactly one of the store's products.
     *
     * @param  list<string>  $productPaths
     * @return array{selector: string, cards: int, products: int}|null
     */
    public static function check(string $html, string $selector, array $productPaths): ?array
    {
        [$doc, $xpath] = self::parse($html);
        $query = self::xpath($selector);

        if ($doc === null || $query === null) {
            return null;
        }

        $known = array_flip(array_map([self::class, 'path'], $productPaths));
        $nodes = @$xpath->query($query);

        if ($nodes === false || $nodes->length < self::MIN_CARDS) {
            return null;
        }

        $products = [];
        foreach ($nodes as $node) {
            $inside = [];
            foreach ($xpath->query('.//a[@href]', $node) ?: [] as $a) {
                $key = self::path((string) $a->getAttribute('href'));
                if (isset($known[$key])) {
                    $inside[$key] = true;
                }
            }

            if (count($inside) !== 1) {
                return null;
            }

            $products[array_key_first($inside)] = true;
        }

        return ['selector' => $selector, 'cards' => $nodes->length, 'products' => count($products)];
    }

    /**
     * How many of the store's products a page links to: none means the page is not a list of them
     * (a block page, a login, an empty collection).
     *
     * @param  list<string>  $productPaths
     */
    public static function linkCount(string $html, array $productPaths): int
    {
        [$doc, $xpath] = self::parse($html);

        return $doc === null ? 0 : count(self::productLinks($xpath, array_flip(array_map([self::class, 'path'], $productPaths))));
    }

    /** A product address as compared: its path, decoded, lower case, without the last slash. */
    public static function path(string $url): string
    {
        $path = (string) (parse_url(html_entity_decode($url), PHP_URL_PATH) ?? '');

        return rtrim(mb_strtolower(rawurldecode($path)), '/');
    }

    /** @return array{0: DOMDocument|null, 1: DOMXPath|null} */
    private static function parse(string $html): array
    {
        if (trim($html) === '') {
            return [null, null];
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? [$doc, new DOMXPath($doc)] : [null, null];
    }

    /**
     * @param  array<string, int>  $known
     * @return array<string, list<DOMElement>> the links to each product, by its path
     */
    private static function productLinks(DOMXPath $xpath, array $known): array
    {
        $links = [];

        foreach ($xpath->query('//body//a[@href]') ?: [] as $a) {
            $key = self::path((string) $a->getAttribute('href'));

            if ($key !== '' && isset($known[$key])) {
                $links[$key][] = $a;
            }
        }

        return $links;
    }

    /**
     * @param  list<DOMElement>  $anchors
     * @param  array<string, int>  $known
     */
    private static function card(array $anchors, string $key, array $known): ?DOMElement
    {
        $node = $anchors[0];
        $best = null;

        while (($parent = $node->parentNode) instanceof DOMElement && ! in_array($parent->nodeName, ['body', 'html', 'main'], true)) {
            $others = false;
            foreach ($parent->getElementsByTagName('a') as $a) {
                $other = self::path((string) $a->getAttribute('href'));
                if ($other !== $key && isset($known[$other])) {
                    $others = true;
                    break;
                }
            }

            if ($others) {
                break;
            }

            $node = $parent;
            if ($node->getElementsByTagName('img')->length > 0 || $node->getElementsByTagName('picture')->length > 0) {
                $best = $node;
            }
        }

        return $best;
    }

    /** A card's tag and its lasting classes, sorted. */
    private static function look(DOMElement $card): string
    {
        return $card->nodeName.'|'.implode(' ', self::classes($card));
    }

    /** @return list<string> */
    private static function classes(DOMElement $node): array
    {
        $classes = array_values(array_filter(
            preg_split('/\s+/', trim((string) $node->getAttribute('class'))) ?: [],
            fn (string $c): bool => $c !== '' && preg_match('/^[A-Za-z_][\w-]*$/', $c) === 1 && preg_match(self::PASSING, $c) !== 1,
        ));
        sort($classes);

        return $classes;
    }

    /**
     * Selectors for cards that look the same, simplest first: one class, then the tag with it,
     * then two classes; a class naming a product first.
     *
     * @param  list<DOMElement>  $cards
     * @return list<string>
     */
    private static function selectors(array $cards): array
    {
        $shared = self::classes($cards[0]);
        foreach ($cards as $card) {
            $shared = array_values(array_intersect($shared, self::classes($card)));
        }
        usort($shared, fn (string $a, string $b): int => [preg_match('/product|card|item/i', $b), -strlen($b)] <=> [preg_match('/product|card|item/i', $a), -strlen($a)]);
        $tag = $cards[0]->nodeName;
        $out = [];

        foreach ($shared as $class) {
            $out[] = '.'.$class;
            $out[] = $tag.'.'.$class;
        }
        foreach ($shared as $i => $a) {
            foreach (array_slice($shared, $i + 1) as $b) {
                $out[] = $tag.'.'.$a.'.'.$b;
            }
        }

        return array_values(array_unique($out));
    }

    /** The selectors this finds and checks, as XPath: a tag, classes, or both. */
    private static function xpath(string $selector): ?string
    {
        $parts = [];

        foreach (array_map('trim', explode(',', $selector)) as $one) {
            if (preg_match('/^([a-z][a-z0-9]*)?((?:\.[A-Za-z_][\w-]*)*)$/', $one, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
                return null;
            }

            $conditions = array_map(fn (string $c): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')", array_values(array_filter(explode('.', $m[2]))));
            $parts[] = '//'.($m[1] !== '' ? $m[1] : '*').($conditions === [] ? '' : '['.implode(' and ', $conditions).']');
        }

        return implode(' | ', $parts);
    }
}
