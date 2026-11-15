<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Http\OutboundUrl;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\StorefrontLock;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogSibling;
use App\Modules\Catalog\Support\PageSignals;
use App\Modules\Catalog\Support\StoreRest;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One model's versions a store sells as products of their own (a ring in amethyst, in citrine; a sandal in black, in
 * brown), as its own collection pages link them: each card's swatches, or the list of linked products an app keeps in
 * the page (PageSignals::siblingGroups). Read in code, page after page of the store's whole catalogue (Shopify's
 * /collections/all, WooCommerce's /shop), a pause between pages, until a page shows no product. The groups are kept
 * (CatalogSibling), and the search draws one model's colours from them: a card's swatches switch it to the others.
 * A store that sells its colours as variants of one product has no such groups, and nothing changes for it. No model.
 */
final class ReadCollectionSiblings
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.collection_siblings';

    private const TIMEOUT_SECONDS = 20;

    /** More products than this in one group is a list of products, not one model's versions. */
    private const GROUP_MOST = 20;

    /** @var Closure(int): void */
    private Closure $pause;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): ?Run
    {
        if (! Features::enabled('catalog.collection_siblings', $shopId) || StoreRest::resting($shopId)) {
            return null;
        }

        return $this->runs->track(agent: self::AGENT, action: self::ACTION, shopId: $shopId, trigger: $trigger, work: function (RunContext $run) use ($shopId): void {
            $this->tenant->run($shopId, function () use ($run, $shopId): void {
                // every product by its own path ("/products/wp-julius-1"), as its address says it
                $byPath = [];
                $base = null;
                foreach (CatalogProduct::query()->active()->whereNotNull('url')->orderBy('id')->get(['id', 'url']) as $product) {
                    $url = (string) $product->url;
                    $path = self::productPath($url);
                    if ($path !== null) {
                        $byPath[$path] = (string) $product->id;
                        $base ??= preg_match('~^(https?://[^/]+)~i', $url, $m) === 1 ? $m[1] : null;
                    }
                }
                if ($base === null || $byPath === []) {
                    $run->output(['pages' => 0, 'groups' => 0, 'products' => 0])->summary('catalog::runs.collection_siblings', ['pages' => '0', 'groups' => '0', 'products' => '0']);

                    return;
                }

                // Shopify's whole catalogue page by page; WooCommerce's shop
                $shopify = array_filter(array_keys($byPath), fn (string $p): bool => str_starts_with($p, '/products/')) !== [];
                $pageUrl = fn (int $n): string => $shopify ? $base.'/collections/all?page='.$n : $base.'/shop/'.($n > 1 ? 'page/'.$n.'/' : '');
                $most = (int) Settings::get('catalog.collection_pages_max', $shopId);
                $delay = (int) Settings::get('catalog.page_choices_delay_ms', $shopId);

                $parent = [];
                $find = function (string $id) use (&$parent, &$find): string {
                    $parent[$id] ??= $id;

                    return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
                };
                $labels = [];
                $seen = [];
                $read = 0;
                $down = 0;
                for ($n = 1; $n <= $most; $n++) {
                    if ($n > 1) {
                        ($this->pause)($delay);
                    }
                    $page = $this->get($pageUrl($n));
                    if (StorefrontLock::of($page) !== null) {
                        break;
                    }
                    if ($page === null || ! $page->successful()) {
                        $down = StoreRest::struggling($page) ? $down + 1 : 0;
                        if ($down >= StoreRest::TIMES) {
                            StoreRest::rest($shopId);

                            break;
                        }

                        continue;
                    }
                    $down = 0;
                    $html = (string) $page->body();
                    // a page that shows no product not seen on the pages before is past its last (its menus link the same
                    // few on every page); any product of the site, not only those read: a demo reads some of a store, and
                    // stopped at the first page of others (Jean Rachel, 16.11: 17 products grouped of hundreds)
                    preg_match_all('~href="([^"]*/products?/[^"?#/]+)~i', $html, $hrefs);
                    $new = array_diff(array_unique(array_filter(array_map(fn (string $h): ?string => self::productPath($h), $hrefs[1]))), array_keys($seen));
                    if ($new === []) {
                        break;
                    }
                    $seen += array_fill_keys($new, true);
                    $read++;
                    foreach (PageSignals::siblingGroups($html, $pageUrl($n)) as $group) {
                        $ids = [];
                        foreach ($group as $member) {
                            $id = $byPath[$member['url']] ?? null;
                            if ($id !== null) {
                                $ids[] = $id;
                                if (($member['label'] ?? '') !== '') {
                                    $labels[$id] ??= $member['label'];
                                }
                            }
                        }
                        foreach (array_slice($ids, 1) as $id) {
                            $parent[$find($id)] = $find($ids[0]);
                        }
                    }
                }

                $groups = [];
                foreach (array_keys($parent) as $id) {
                    $groups[$find($id)][] = $id;
                }
                $groups = array_values(array_filter($groups, fn (array $members): bool => count($members) >= 2 && count($members) <= self::GROUP_MOST));

                $rows = [];
                $now = now();
                foreach ($groups as $members) {
                    sort($members);
                    $key = substr(md5(implode(',', $members)), 0, 16);
                    foreach ($members as $id) {
                        $rows[] = ['id' => (string) Str::ulid(), 'shop_id' => $shopId, 'product_id' => $id, 'group_key' => $key, 'label' => isset($labels[$id]) ? mb_substr($labels[$id], 0, 120) : null, 'created_at' => $now, 'updated_at' => $now];
                    }
                }

                $before = CatalogSibling::query()->orderBy('product_id')->pluck('group_key', 'product_id')->all();
                // read nothing (the store did not answer): what was read before stays
                if ($read > 0) {
                    DB::transaction(function () use ($rows): void {
                        CatalogSibling::query()->delete();
                        foreach (array_chunk($rows, 500) as $chunk) {
                            CatalogSibling::query()->insert($chunk);
                        }
                    });
                }
                $after = CatalogSibling::query()->orderBy('product_id')->pluck('group_key', 'product_id')->all();
                // the search draws the colours of one model from them: built again when they changed
                if ($after !== $before) {
                    event(new CatalogProductsChanged($shopId, []));
                }

                $run->output([
                    'pages' => $read,
                    'groups' => count($groups),
                    'products' => count($rows),
                    'examples' => array_slice(array_map(fn (array $members): array => array_values(array_filter(array_map(fn (string $id): ?string => $labels[$id] ?? null, $members))), $groups), 0, 5),
                ])->summary('catalog::runs.collection_siblings', ['pages' => (string) $read, 'groups' => (string) count($groups), 'products' => (string) count($rows)]);
            });
        });
    }

    /** A product's own path ("/products/wp-julius-1", "/product/ring"), lower case, from its address; null for another. */
    public static function productPath(string $url): ?string
    {
        $path = strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));

        return preg_match('~(/products?/[^/]+)$~i', $path, $m) === 1 ? $m[1] : null;
    }

    private function get(string $url): ?Response
    {
        try {
            return OutboundUrl::request($url)?->withHeaders(['User-Agent' => 'LetsSearch/1.0 (+https://agents.lets.co.il)', 'Accept' => 'text/html'])->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable) {
            return null;
        }
    }
}
