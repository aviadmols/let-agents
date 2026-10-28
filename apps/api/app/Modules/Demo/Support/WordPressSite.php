<?php

namespace App\Modules\Demo\Support;

use App\Core\Text\HtmlText;
use Generator;
use Illuminate\Support\Facades\Cache;

/**
 * A WordPress site with no WooCommerce, read for a demo from what it publishes for anyone: its content types, which of
 * them it offers (a tour, an experience, a course, an event: a page with what it costs or how long it lasts and a way to
 * ask for it) and which it writes (its articles), each item's own words without the template every page repeats. Read
 * in code, no model. Its other types (a team, testimonials, a podcast, its internal pages) are not read: a shopper
 * searching it finds what it offers and what it wrote about it.
 *
 * Gil Travel, 27.10: tours and experiences kept their text in Elementor templates and ACF fields, so the API gave them
 * an empty body; and every page carried the same menu, cookie banner and "Make an Inquiry", so a post looked like a tour
 * until the lines most pages repeat were taken out.
 */
final class WordPressSite
{
    public const OFFERING = 'offering';

    public const ARTICLE = 'article';

    /** Types that are WordPress's or a builder's own, a store's, or the site's internal pages: never read. */
    private const NOT_CONTENT = '/^(?:page|attachment|nav_menu_item|wp_[a-z_]+|elementor_[a-z_]+|e-landing-page|product|product_variation|shop_[a-z_]+|acf-[a-z-]+|oembed_cache|user_request|custom_css|customize_changeset|revision|templately_[a-z_]+|jet-[a-z-]+|ct_template|fl-builder-template|et_pb_layout|wpcf7_contact_form|mc4wp-form)$/';

    /** Pages read of each type to tell what it is. */
    private const SAMPLE = 3;

    /** A line on more than this share of the pages read is the site's template (its menu, footer, banners), not a page's. */
    private const TEMPLATE_SHARE = 0.5;

    /** A type is what its pages say when this many of the pages read say it. */
    private const AGREE = 2;

    /** Items read of a type, at most: the catalogue's own cap stops the read before. */
    private const MAX_PAGES = 20;

    private const PER_PAGE = 50;

    /** A pause between pages: a demo never weighs on the site. */
    private const PAUSE_MS = 400;

    /** What a page offers says it asks for something: a price, a way to book or ask, or how long it lasts. */
    private const PRICE = '/(?:\bfrom|\bprice[:\s]*|\bstarting at|\bonly|מחיר[:\s]*|החל מ[-־]?)\s*\*?\s*([$€£₪])\s?(\d[\d,]*(?:\.\d{1,2})?)|(\d[\d,]*(?:\.\d{1,2})?)\s?(₪|ש"ח|ש״ח|USD|EUR|ILS|GBP)\b/iu';

    /** An item's own price is stated by its title, in its first lines: one further down is another item's (a carousel of others). */
    private const PRICE_LINES = 12;

    private const ASK = '/make an inquiry|inquire now|enquire|request a quote|book now|book this|reserve|register now|enroll|buy tickets|get tickets|add to cart|להזמנה|להרשמה|השאירו פרטים|לפרטים והזמנות|הזמינו עכשיו/iu';

    private const LENGTH = '/\b\d{1,2}[ -](?:days?|nights?|hours?|weeks?)\b|\bday\s?\d{1,2}\b|\d{1,2}\s?(?:ימים|לילות|שעות)|יום\s?\d{1,2}/iu';

    /** What structured data says a page offers. */
    private const OFFER_SCHEMA = ['Product', 'Offer', 'Event', 'TouristTrip', 'Trip', 'Course', 'Service', 'EducationEvent', 'MusicEvent', 'SportsEvent', 'TheaterEvent', 'ExhibitionEvent'];

    private const ARTICLE_SCHEMA = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle'];

    private const CURRENCY = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '₪' => 'ILS', 'ש"ח' => 'ILS', 'ש״ח' => 'ILS', 'USD' => 'USD', 'EUR' => 'EUR', 'ILS' => 'ILS', 'GBP' => 'GBP'];

    /**
     * The site's content types worth reading, each with what it is, and the lines of its template. Read once a few hours.
     *
     * @return array{types: array<string, array{rest: string, name: string, role: string, total: int}>, template: list<string>, currency: string|null}
     */
    public static function read(string $host): array
    {
        return Cache::remember('demo:wordpress:'.$host, now()->addHours(6), fn (): array => self::discover($host));
    }

    /** @return array{types: array<string, array{rest: string, name: string, role: string, total: int}>, template: list<string>, currency: string|null} */
    private static function discover(string $host): array
    {
        $listed = Sitemap::types($host);
        $candidates = [];
        $samples = [];

        foreach ((array) PublicSite::json($host, '/wp-json/wp/v2/types') as $slug => $type) {
            $slug = (string) $slug;
            $rest = (string) ($type['rest_base'] ?? '');
            // only what the site lists for search engines, when it lists any: a team, testimonials, internal types are not
            if ($rest === '' || preg_match(self::NOT_CONTENT, $slug) === 1 || ($listed !== null && ! in_array($slug, $listed, true))) {
                continue;
            }
            $response = PublicSite::get($host, '/wp-json/wp/v2/'.$rest, ['per_page' => self::SAMPLE, '_fields' => 'id,link']);
            $total = (int) $response?->header('X-WP-Total');
            $items = (array) $response?->json();
            if ($total < 1 || ! array_is_list($items)) {
                continue;
            }
            $candidates[$slug] = ['rest' => $rest, 'name' => html_entity_decode((string) ($type['name'] ?? $slug), ENT_QUOTES | ENT_HTML5), 'total' => $total];
            foreach (array_slice($items, 0, self::SAMPLE) as $item) {
                $html = is_array($item) && isset($item['link']) ? (string) PublicSite::get($host, (string) $item['link'])?->body() : '';
                if ($html !== '') {
                    $samples[$slug][] = $html;
                }
                self::pause();
            }
        }

        $template = self::template(array_merge(...array_values($samples ?: [[]])));
        $types = [];
        foreach ($candidates as $slug => $type) {
            $role = $slug === 'post' ? self::ARTICLE : self::role($samples[$slug] ?? [], $template);
            if ($role !== null) {
                $types[$slug] = $type + ['role' => $role];
            }
        }
        // what is offered first, the most of it first
        uasort($types, fn (array $a, array $b): int => [$a['role'] !== self::OFFERING, -$a['total']] <=> [$b['role'] !== self::OFFERING, -$b['total']]);
        // the site's currency: the one its offered items' own prices state most (Gil Travel: dollars)
        $currencies = [];
        foreach (array_keys(array_filter($types, fn (array $t): bool => $t['role'] === self::OFFERING)) as $slug) {
            foreach ($samples[$slug] ?? [] as $html) {
                $code = self::price(implode("\n", array_slice(self::own($html, $template), 0, self::PRICE_LINES)))[0];
                if ($code !== '') {
                    $currencies[$code] = ($currencies[$code] ?? 0) + 1;
                }
            }
        }
        arsort($currencies);

        return ['types' => $types, 'template' => array_keys($template), 'currency' => array_key_first($currencies)];
    }

    /**
     * The lines most of these pages repeat: the site's menu, footer, banners and forms.
     *
     * @param  list<string>  $pages
     * @return array<string, true>
     */
    public static function template(array $pages): array
    {
        if (count($pages) < 4) {
            return [];
        }
        $seen = [];
        foreach ($pages as $html) {
            foreach (array_unique(self::lines($html)) as $line) {
                $seen[$line] = ($seen[$line] ?? 0) + 1;
            }
        }

        return array_fill_keys(array_keys(array_filter($seen, fn (int $n): bool => $n > count($pages) * self::TEMPLATE_SHARE)), true);
    }

    /** What these pages say a type is: offered, written, or neither (null). */
    private static function role(array $pages, array $template): ?string
    {
        $offers = 0;
        $articles = 0;
        foreach ($pages as $html) {
            $schema = self::schemaTypes($html);
            if (array_intersect($schema, self::ARTICLE_SCHEMA) !== [] || preg_match('~<meta[^>]+property=["\']article:published_time~i', $html) === 1) {
                $articles++;
            }
            $own = implode("\n", self::own($html, $template));
            $signals = (int) (preg_match(self::PRICE, $own) === 1) + (int) (preg_match(self::ASK, $own) === 1) + (int) (preg_match(self::LENGTH, $own) === 1)
                + (array_intersect($schema, self::OFFER_SCHEMA) !== [] ? 2 : 0);
            $offers += $signals >= 2 ? 1 : 0;
        }

        return match (true) {
            $offers >= self::AGREE && $offers >= $articles => self::OFFERING,
            $articles >= self::AGREE => self::ARTICLE,
            default => null,
        };
    }

    /**
     * The items of one type, a page of the site's API at a time, each with its picture and terms.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public static function items(string $host, string $rest): Generator
    {
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = ['per_page' => self::PER_PAGE, 'page' => $page, '_embed' => 'wp:featuredmedia,wp:term'];
            $items = PublicSite::json($host, '/wp-json/wp/v2/'.$rest, $query);
            // a page that did not answer is asked once more after a breath: one slow answer never cuts the read short
            // (Gil Travel, 28.10: 81 of 143 read when the experiences' second page timed out). Past the last page the
            // API answers an error, which ends the read.
            if ($items === null) {
                self::pause(5);
                $items = PublicSite::json($host, '/wp-json/wp/v2/'.$rest, $query);
            }
            if ($items === null || $items === [] || ! array_is_list($items)) {
                return;
            }
            yield array_values(array_filter($items, fn ($i): bool => is_array($i) && isset($i['id'])));
            self::pause();
        }
    }

    /**
     * One offered item as a product of the feed: its own words (from its page when the API gives none), the price its
     * page states and nothing it does not, its picture, the categories and tags the site files it under, and its type's
     * name as what kind of thing it is. Never bought in a cart: the site asks for it in its own way.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $template
     * @return array<string, mixed>
     */
    public static function product(string $host, array $item, string $typeName, array $template): array
    {
        $html = (string) PublicSite::get($host, (string) ($item['link'] ?? ''))?->body();
        $title = self::title($item);
        $own = self::body(self::own($html, array_fill_keys($template, true)), $title);
        $body = PublicSite::text((string) ($item['content']['rendered'] ?? ''));
        $text = mb_strlen($body) >= 200 ? $body : implode("\n", $own);
        [$currency, $price] = self::price(implode("\n", array_slice($own, 0, self::PRICE_LINES)));
        [$categories, $tags] = self::terms($item);
        $image = self::picture($item) ?? self::ogImage($html);

        return [
            'external_id' => (string) $item['id'],
            'type' => 'simple',
            'status' => 'publish',
            'title' => $title,
            'slug' => $item['slug'] ?? null,
            'url' => $item['link'] ?? null,
            'sku' => null,
            'short_description' => mb_substr(PublicSite::text((string) ($item['excerpt']['rendered'] ?? '')), 0, 500),
            'description' => mb_substr($text, 0, 20_000),
            'images' => $image === '' ? [] : [['url' => $image, 'alt' => '']],
            'categories' => $categories,
            'tags' => array_values(array_unique([...$tags, ...self::crumbs($html)])),
            'brands' => [],
            'product_type' => $typeName,
            'attributes' => [],
            'meta' => [],
            'relations' => [],
            'price' => ['currency' => $currency, 'price' => $price, 'regular_price' => $price, 'on_sale' => false, 'min_price' => $price, 'max_price' => $price],
            'stock' => ['in_stock' => true, 'managed' => false, 'quantity' => null],
            'variations' => [],
            'purchasable' => false,
            'created_at' => $item['date_gmt'] ?? $item['date'] ?? null,
            'updated_at' => $item['modified_gmt'] ?? $item['modified'] ?? null,
        ];
    }

    /**
     * One written item as an article of the feed: its words from the API, or from its page when the API gives none.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $template
     * @return array<string, mixed>
     */
    public static function article(string $host, array $item, array $template): array
    {
        $text = PublicSite::text((string) ($item['content']['rendered'] ?? ''));
        if (mb_strlen($text) < 200) {
            $text = implode("\n", self::body(self::own((string) PublicSite::get($host, (string) ($item['link'] ?? ''))?->body(), array_fill_keys($template, true)), self::title($item)));
        }
        $terms = [];
        foreach ((array) ($item['_embedded']['wp:term'] ?? []) as $group) {
            foreach ((array) $group as $term) {
                if (is_array($term) && isset($term['name'])) {
                    $terms[(string) ($term['taxonomy'] ?? 'category')][] = html_entity_decode((string) $term['name'], ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        return [
            'external_id' => (string) $item['id'], 'type' => 'post', 'title' => self::title($item), 'slug' => $item['slug'] ?? null, 'url' => $item['link'] ?? null,
            'excerpt' => mb_substr(PublicSite::text((string) ($item['excerpt']['rendered'] ?? '')) ?: $text, 0, 300), 'text' => mb_substr($text, 0, 60_000),
            'image' => self::picture($item), 'terms' => $terms, 'product_ids' => [],
            'created_at' => $item['date_gmt'] ?? $item['date'] ?? null, 'updated_at' => $item['modified_gmt'] ?? $item['modified'] ?? null,
        ];
    }

    /**
     * The terms of the site's hierarchical taxonomies its offered types are filed under, as the catalogue's categories.
     * None is shown as a result of its own (no product count): an archive page is the site's internal page.
     *
     * @param  list<string>  $types  the offered types' slugs
     * @return list<array<string, mixed>>
     */
    public static function categories(string $host, array $types): array
    {
        $out = [];
        foreach ((array) PublicSite::json($host, '/wp-json/wp/v2/taxonomies') as $slug => $taxonomy) {
            if (! is_array($taxonomy) || ! ($taxonomy['hierarchical'] ?? false) || array_intersect((array) ($taxonomy['types'] ?? []), $types) === [] || ($taxonomy['rest_base'] ?? '') === '') {
                continue;
            }
            for ($page = 1; $page <= 10; $page++) {
                $terms = PublicSite::json($host, '/wp-json/wp/v2/'.$taxonomy['rest_base'], ['per_page' => 100, 'page' => $page, '_fields' => 'id,name,slug,parent,link']);
                if ($terms === null || $terms === [] || ! array_is_list($terms)) {
                    break;
                }
                foreach ($terms as $term) {
                    $name = html_entity_decode((string) ($term['name'] ?? ''), ENT_QUOTES | ENT_HTML5);
                    $out[] = ['external_id' => 'wp:'.$slug.':'.$term['id'], 'name' => $name, 'parent_id' => ($term['parent'] ?? 0) ? 'wp:'.$slug.':'.$term['parent'] : null,
                        'path' => [$name], 'product_count' => 0, 'url' => $term['link'] ?? null, 'image' => null];
                }
                self::pause();
            }
        }

        return $out;
    }

    /**
     * A page's lines of text, read as every page of a store is read (HtmlText: headings as "## ", list items as "- ",
     * tables as rows), never its head, its scripts and styles, a line of code a builder's widget printed, or placeholder
     * text ("Lorem ipsum").
     *
     * @return list<string>
     */
    public static function lines(string $html): array
    {
        $html = (string) preg_replace('~<head\b.*?</head>|<(noscript|svg|template|iframe|form)\b.*?</\1>~is', ' ', $html);
        $out = [];
        foreach (explode("\n", HtmlText::from($html)) as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
            $plain = trim((string) preg_replace('/^(?:#{2,3}|-)\s+/u', '', $line));
            if (mb_strlen($plain) < 2 || self::code($plain) || preg_match('/lorem ipsum/i', $plain) === 1) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * A page's own lines: without the template's.
     *
     * @param  array<string, true>  $template
     * @return list<string>
     */
    public static function own(string $html, array $template): array
    {
        return array_values(array_filter(self::lines($html), fn (string $line): bool => ! isset($template[$line])));
    }

    /**
     * An item's own lines as its text: never its breadcrumb trail ("Main › Tours › …"), its title again, or a line the
     * page repeats (its tabs, printed twice for the phone and the desktop).
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function body(array $lines, string $title): array
    {
        $plain = fn (string $s): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
        $seen = [];
        $out = [];
        foreach ($lines as $line) {
            $key = $plain($line);
            if ($key === '' || isset($seen[$key]) || $key === $plain($title) || preg_match('/^\S+(?:\s\S+)?\s[›»]\s/u', $line) === 1) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $line;
        }

        return $out;
    }

    /**
     * An item's picture in a size fit for a card, not the full upload.
     *
     * @param  array<string, mixed>  $item
     */
    private static function picture(array $item): ?string
    {
        $media = (array) ($item['_embedded']['wp:featuredmedia'][0] ?? []);
        $sizes = (array) ($media['media_details']['sizes'] ?? []);
        foreach (['medium_large', 'large', 'full'] as $size) {
            if (is_string($sizes[$size]['source_url'] ?? null) && $sizes[$size]['source_url'] !== '') {
                return $sizes[$size]['source_url'];
            }
        }

        return is_string($media['source_url'] ?? null) && $media['source_url'] !== '' ? $media['source_url'] : null;
    }

    /** A line of CSS or JavaScript a widget printed into the page. */
    private static function code(string $line): bool
    {
        return preg_match('~^(?:/\*|\*/|//|[.#@][\w-]+[^{]*\{?$|[\w-]+\s*:\s*[^:]{1,80};$|\}+[;,)]*$|\{$|(?:var|let|const|function|jQuery|\$\(|if\s*\(|return\b)|\d+\s*:\s*\{)~u', $line) === 1
            || (preg_match('/[{};]\s*$/u', $line) === 1 && preg_match('/[a-z-]+\s*:|\(|\)/i', $line) === 1);
    }

    /**
     * The first price a page states as its own, and its currency; empty strings when it states none.
     *
     * @return array{0: string, 1: string}
     */
    public static function price(string $text): array
    {
        if (preg_match(self::PRICE, $text, $m) !== 1) {
            return ['', ''];
        }
        $symbol = ($m[1] ?? '') !== '' ? $m[1] : (string) ($m[4] ?? '');
        $amount = ($m[2] ?? '') !== '' ? $m[2] : (string) ($m[3] ?? '');

        return [self::CURRENCY[$symbol] ?? self::CURRENCY[strtoupper($symbol)] ?? '', (string) (float) str_replace(',', '', $amount)];
    }

    /** @return list<string> the @type values of a page's structured data */
    private static function schemaTypes(string $html): array
    {
        preg_match_all('~"@type"\s*:\s*(?:"([^"]+)"|\[([^\]]+)\])~', $html, $m);
        $types = [];
        foreach ($m[1] as $i => $one) {
            foreach ($one !== '' ? [$one] : array_map(fn (string $t): string => trim($t, " \"\t"), explode(',', $m[2][$i])) as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /** @return list<string> the page's breadcrumb trail between its home and itself (BreadcrumbList) */
    private static function crumbs(string $html): array
    {
        if (preg_match('~"@type"\s*:\s*"BreadcrumbList".*?"itemListElement"\s*:\s*(\[.*?\])\s*\}~s', $html, $m) !== 1) {
            return [];
        }
        preg_match_all('~"name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"~', $m[1], $names);
        $names = array_map(fn (string $n): string => html_entity_decode((string) json_decode('"'.$n.'"'), ENT_QUOTES | ENT_HTML5), $names[1]);

        return array_values(array_filter(array_slice($names, 1, -1), fn (string $n): bool => $n !== ''));
    }

    /** A polite pause between requests to the site; none in tests, where the site is a fake. */
    private static function pause(int $times = 1): void
    {
        if (! app()->runningUnitTests()) {
            usleep(self::PAUSE_MS * 1000 * $times);
        }
    }

    private static function ogImage(string $html): string
    {
        return preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)~i', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    /** @param array<string, mixed> $item */
    private static function title(array $item): string
    {
        return trim(html_entity_decode(strip_tags((string) ($item['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * An item's terms: those of hierarchical taxonomies as its categories (by id), the rest as tags (by name).
     *
     * @param  array<string, mixed>  $item
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private static function terms(array $item): array
    {
        $categories = [];
        $tags = [];
        foreach ((array) ($item['_embedded']['wp:term'] ?? []) as $group) {
            foreach ((array) $group as $term) {
                if (! is_array($term) || ! isset($term['id'], $term['name'])) {
                    continue;
                }
                $name = html_entity_decode((string) $term['name'], ENT_QUOTES | ENT_HTML5);
                $taxonomy = (string) ($term['taxonomy'] ?? 'category');
                if ($taxonomy === 'post_tag' || str_ends_with($taxonomy, '_tag')) {
                    $tags[] = $name;
                } else {
                    $categories[] = ['id' => 'wp:'.$taxonomy.':'.$term['id'], 'name' => $name, 'slug' => $term['slug'] ?? null, 'path' => [$name]];
                }
            }
        }

        return [$categories, $tags];
    }
}
