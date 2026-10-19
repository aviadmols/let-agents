<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Core\Text\HtmlText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Support\StoreRest;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every page of the store's own site the assistant should know, whatever the platform gave the feed:
 * the pages its sitemap lists (not products, categories, tags or authors, which the catalogue has),
 * and on Shopify its policies, which no sitemap lists. A page the feed already brought (the same
 * address) is not read twice; a page read before and unchanged is not read again; one that left the
 * site leaves the catalogue. Read in code, the text with its headings, lists and tables (HtmlText).
 *
 * So what a shopper asks is answered from where the store wrote it: a return policy, a sizes guide,
 * an FAQ, delivery terms, opening hours, on any store, without anyone pointing at the page.
 *
 * Only the store's own host, only public addresses, a bounded number of pages a run.
 */
final class ReadSitePages
{
    public const AGENT = 'catalog.site_pages';

    public const ACTION = 'catalog.read_site_pages';

    /** The external id of a page this reads: never removed by a feed sync (CatalogContent::OWN_PREFIX). */
    public const PREFIX = CatalogContent::OWN_PREFIX.'site-';

    /** The policies every Shopify storefront serves, when the store wrote them. */
    private const SHOPIFY_POLICIES = ['refund-policy', 'shipping-policy', 'terms-of-service', 'privacy-policy', 'contact-information', 'legal-notice'];

    /** Sitemap entries that are the catalogue's, not pages: products, categories, collections, tags, authors. */
    private const NOT_PAGES = '~/(products?|product-category|product-tag|collections?|category|tag|author|cart|checkout|account|my-account|search)(/|$|\?)|sitemap_products|sitemap_collections|product-sitemap|product_cat-sitemap|product_tag-sitemap|category-sitemap|post_tag-sitemap|author-sitemap~iu';

    private const MAX_SITEMAPS = 12;

    private const MAX_BYTES = 2_000_000;

    /** A page with less text than this says nothing a shopper asks about. */
    private const MIN_CHARS = 80;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::System): ?Run
    {
        // A site that struggled a moment ago is left alone a while (StoreRest).
        if (! Features::enabled('catalog.site_pages', $shopId) || StoreRest::resting($shopId)) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $shopId)),
        );
    }

    private function read(RunContext $run, string $shopId): void
    {
        $shop = Shop::query()->findOrFail($shopId);
        $host = strtolower((string) preg_replace('~^https?://|/.*$~i', '', (string) $shop->domain));
        $counts = ['listed' => 0, 'read' => 0, 'new' => 0, 'changed' => 0, 'same' => 0, 'skipped' => 0, 'removed' => 0, 'failed' => 0];

        if ($host === '' || ! self::public($host)) {
            $run->output($counts + ['why' => 'no_public_host'])->summary('catalog::runs.site_pages_none');

            return;
        }

        $base = 'https://'.$host;
        $urls = $this->sitemapPages($base, $host);

        if ($shop->platform === ShopPlatform::Shopify) {
            foreach (self::SHOPIFY_POLICIES as $handle) {
                $urls[$base.'/policies/'.$handle] = true;
            }
        }

        $counts['listed'] = count($urls);
        // The feed's own pages and posts by address: not read twice.
        $fed = CatalogContent::query()->whereNull('removed_at')->where('external_id', 'not like', CatalogContent::OWN_PREFIX.'%')
            ->whereNotNull('url')->pluck('url')->map(fn ($u): string => self::norm((string) $u))->flip();
        $mine = CatalogContent::query()->where('external_id', 'like', self::PREFIX.'%')->get()->keyBy('external_id');
        $limit = (int) Settings::get('catalog.site_pages_per_run', $shopId);
        $seen = [];

        $down = 0;

        foreach (array_keys($urls) as $url) {
            $id = self::PREFIX.substr(hash('sha256', self::norm($url)), 0, 24);
            $seen[$id] = true;

            if (isset($fed[self::norm($url)])) {
                $counts['skipped']++;

                continue;
            }

            if ($counts['read'] >= $limit) {
                // Read on the next run; what was read before stays meanwhile.
                continue;
            }

            $page = $this->page($url, $host);
            $counts['read']++;

            if ($page === null) {
                $counts['failed']++;
                // The site struggles, again and again: stop reading it and let it be (StoreRest).
                $down = $this->down ? $down + 1 : 0;
                if ($down >= StoreRest::TIMES) {
                    StoreRest::rest($shopId);

                    break;
                }

                continue;
            }
            $down = 0;

            $hash = hash('sha256', $page['title'].'|'.$page['text']);
            $had = $mine->get($id);

            if ($had !== null && $had->hash === $hash && $had->removed_at === null) {
                $counts['same']++;

                continue;
            }

            CatalogContent::query()->updateOrCreate(['external_id' => $id], [
                'shop_id' => $shopId, 'type' => CatalogContent::PAGE, 'title' => mb_substr($page['title'], 0, 250), 'url' => mb_substr($url, 0, 1000),
                'excerpt' => mb_substr($page['text'], 0, 300), 'body' => $page['text'], 'terms' => [], 'product_external_ids' => [],
                'hash' => $hash, 'synced_at' => now(), 'removed_at' => null,
            ]);
            $counts[$had === null ? 'new' : 'changed']++;
        }

        // A page that left the site leaves the catalogue; one not read this run because of the bound stays.
        foreach ($mine as $id => $page) {
            if (! isset($seen[$id]) && $page->removed_at === null) {
                $page->forceFill(['removed_at' => now()])->save();
                $counts['removed']++;
            }
        }

        $run->output($counts)->summary('catalog::runs.site_pages', [
            'new' => (string) $counts['new'], 'changed' => (string) $counts['changed'], 'removed' => (string) $counts['removed'],
        ]);
    }

    /**
     * The page addresses of the store's sitemap (and the sitemaps it lists), on its own host.
     *
     * @return array<string, true>
     */
    private function sitemapPages(string $base, string $host): array
    {
        $queue = [$base.'/sitemap.xml', $base.'/sitemap_index.xml', $base.'/wp-sitemap.xml'];
        $done = [];
        $pages = [];

        while ($queue !== [] && count($done) < self::MAX_SITEMAPS) {
            $map = array_shift($queue);

            if (isset($done[$map])) {
                continue;
            }

            $done[$map] = true;
            $xml = $this->fetch($map, $host);

            if ($xml === null || preg_match_all('~<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>~iu', $xml, $m) < 1) {
                continue;
            }

            $index = stripos($xml, '<sitemapindex') !== false;

            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8');

                if (strtolower((string) parse_url($loc, PHP_URL_HOST)) !== $host && strtolower((string) parse_url($loc, PHP_URL_HOST)) !== 'www.'.$host) {
                    continue;
                }

                if (preg_match(self::NOT_PAGES, (string) parse_url($loc, PHP_URL_PATH).(string) parse_url($loc, PHP_URL_QUERY)) === 1 || preg_match(self::NOT_PAGES, $loc) === 1) {
                    continue;
                }

                if ($index || preg_match('~\.xml(\?|$)~i', $loc) === 1) {
                    $queue[] = $loc;
                } else {
                    $pages[$loc] = true;
                }
            }
        }

        return $pages;
    }

    /**
     * A page's heading and its main text, structure kept; null when it cannot be read or says too little.
     *
     * @return array{title: string, text: string}|null
     */
    private function page(string $url, string $host): ?array
    {
        $html = $this->fetch($url, $host);

        if ($html === null) {
            return null;
        }

        $html = (string) preg_replace('~<(script|style|noscript|svg|form|iframe)\b.*?</\1>~siu', ' ', $html);
        $main = preg_match('~<main\b[^>]*>(.*?)</main>~siu', $html, $m) === 1 ? $m[1]
            : (preg_match('~<article\b[^>]*>(.*?)</article>~siu', $html, $a) === 1 ? $a[1] : $html);
        $main = (string) preg_replace('~<(header|nav|footer|aside)\b.*?</\1>~siu', ' ', $main);
        $title = preg_match('~<h1\b[^>]*>(.*?)</h1>~siu', $main, $h) === 1 ? $h[1] : (preg_match('~<title\b[^>]*>(.*?)</title>~siu', $html, $t) === 1 ? $t[1] : '');
        $title = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = HtmlText::from($main);

        return mb_strlen($text) < self::MIN_CHARS || $title === '' ? null : ['title' => $title, 'text' => mb_substr($text, 0, 60_000)];
    }

    /** The last fetch found the site struggling (StoreRest): no answer, a server error, or "too many requests". */
    private bool $down = false;

    private function fetch(string $url, string $host): ?string
    {
        $this->down = false;

        try {
            $response = Http::timeout(15)->withHeaders(['Accept' => 'text/html,application/xml', 'User-Agent' => 'LetAgents/1.0 (+site pages)'])
                ->withOptions(['allow_redirects' => ['max' => 3, 'on_redirect' => function ($request, $response, $uri) use ($host): void {
                    $to = strtolower($uri->getHost());
                    if ($to !== $host && $to !== 'www.'.$host && ! str_ends_with($host, '.'.$to)) {
                        throw new \RuntimeException('redirect away from the store');
                    }
                }]])->get($url);
        } catch (Throwable) {
            $this->down = true;

            return null;
        }

        if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
            $this->down = StoreRest::struggling($response);

            return null;
        }

        return $response->body();
    }

    /** A host that resolves only to public addresses: never the platform's own network. */
    private static function public(string $host): bool
    {
        if (app()->runningUnitTests()) {
            return true;
        }

        $ips = gethostbynamel($host) ?: [];

        return $ips !== [] && array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) === [];
    }

    private static function norm(string $url): string
    {
        return rtrim(strtolower((string) preg_replace('~^https?://(www\.)?|[?#].*$~i', '', urldecode($url))), '/');
    }
}
