<?php

namespace App\Modules\Catalog\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * What a product page shows that a store's feed may not carry, read in code: products linked to it as
 * its other versions (Jean Rachel's product__page__siblings: the same ring in another metal or stone,
 * each a product of its own), a subscription (a Shopify selling plan, or an app such as Recharge on
 * the page), and choices the page asks for before the cart (Shopify line item properties). For
 * SamplePages, which reads a few pages of every kind before the rest of a scan.
 */
final class PageSignals
{
    /** A block of a page that holds a product's other versions: siblings, swatches, linked products. */
    private const SIBLING_BLOCK = '/sibling|swatch|linked[-_]?product|variant[-_]?link|colou?r[-_]?(?:option|picker|link)|product[-_]?(?:option|variant)s?[-_]?link/i';

    /** Lists of other products, not versions of this one: recommendations, recently viewed, the menus. */
    private const NOT_SIBLINGS = '/related|recommend|upsell|cross[-_]?sell|also[-_]?(?:like|bought)|recent|complementary|you[-_]?may|header|footer|menu|nav|cart[-_]?drawer|search/i';

    /** More product links than this in one block is a list of products, not one product's versions. */
    private const MAX_SIBLINGS = 20;

    /** Subscription apps by what they put on a product page. */
    private const SUBSCRIPTION_APPS = [
        'Recharge' => '/rc_container|rc-widget|rc_widget|rechargepayments|recharge-subscription/i',
        'Appstle' => '/appstle/i',
        'Seal' => '/seal-subscriptions|sealsubscriptions/i',
        'Bold' => '/bold-subscriptions|bold_subscriptions/i',
        'Loop' => '/loop-subscription|loopwork/i',
        'Skio' => '/\bskio\b/i',
        'Stay AI' => '/stay-ai|retextion/i',
        'Ordergroove' => '/ordergroove|og-offer/i',
        'Smartrr' => '/smartrr/i',
        'WooCommerce Subscriptions' => '/woocommerce-subscriptions|wc_subscriptions|subscription_price_string|wcsatt/i',
        'YITH Subscription' => '/ywsbs/i',
    ];

    /**
     * The other products a page links to as this one's versions, each with how the page names it.
     *
     * @return list<array{url: string, label: string}>
     */
    public static function siblings(string $html, string $pageUrl): array
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $self = rtrim((string) parse_url($pageUrl, PHP_URL_PATH), '/');
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $found = [];

        foreach ($xpath->query('//*[@class or @id]') ?: [] as $block) {
            if (! $block instanceof DOMElement || preg_match(self::SIBLING_BLOCK, $block->getAttribute('class').' '.$block->getAttribute('id')) !== 1 || self::insideList($block)) {
                continue;
            }

            $links = [];
            foreach ($xpath->query('.//a[@href]', $block) ?: [] as $a) {
                if (! $a instanceof DOMElement) {
                    continue;
                }
                $href = $a->getAttribute('href');
                $linkHost = strtolower((string) (parse_url($href, PHP_URL_HOST) ?? $host));
                $path = rtrim((string) parse_url($href, PHP_URL_PATH), '/');

                if ($linkHost !== $host || $path === $self || preg_match('~/products?/[^/]+$~i', $path) !== 1) {
                    continue;
                }

                $label = trim((string) preg_replace('/\s+/u', ' ', $a->getAttribute('title') ?: $a->getAttribute('aria-label') ?: $a->getAttribute('data-name') ?: $a->textContent));
                $links[$path] = ['url' => $path, 'label' => mb_substr($label, 0, 80)];
            }

            if ($links !== [] && count($links) <= self::MAX_SIBLINGS) {
                $found += $links;
            }
        }

        return array_values($found);
    }

    /** @return list<string> the subscription apps a page carries */
    public static function subscriptionApps(string $html): array
    {
        return array_keys(array_filter(self::SUBSCRIPTION_APPS, fn (string $pattern): bool => preg_match($pattern, $html) === 1));
    }

    /**
     * Shopify's subscription plans for a product, from its JSON (/products/{handle}.js).
     *
     * @param  array<string, mixed>  $product
     * @return array{plans: int, required: bool}
     */
    public static function shopifyPlans(array $product): array
    {
        return ['plans' => count((array) ($product['selling_plan_groups'] ?? [])), 'required' => (bool) ($product['requires_selling_plan'] ?? false)];
    }

    /** @return list<string> the choices a Shopify page asks for before the cart (properties[...]), not its hidden ones */
    public static function pageFields(string $html): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $tags);
        $names = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/name\s*=\s*["\']properties\[([^\]"\']+)\]["\']/i', $tag, $m) === 1 && preg_match('/type\s*=\s*["\']hidden["\']/i', $tag) !== 1 && ! str_starts_with(trim($m[1]), '_')) {
                $names[trim($m[1])] = true;
            }
        }

        return array_keys($names);
    }

    /** Whether a block sits inside a list of other products or the site's frame (NOT_SIBLINGS). */
    private static function insideList(DOMNode $node): bool
    {
        for ($at = $node; $at instanceof DOMElement; $at = $at->parentNode) {
            if (preg_match(self::NOT_SIBLINGS, $at->getAttribute('class').' '.$at->getAttribute('id')) === 1 || in_array(strtolower($at->tagName), ['header', 'footer', 'nav'], true)) {
                return true;
            }
        }

        return false;
    }
}
