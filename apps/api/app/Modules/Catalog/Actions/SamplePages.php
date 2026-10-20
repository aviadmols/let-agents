<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\PageSignals;
use App\Modules\Catalog\Support\StoreRest;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Before the rest of a scan, a few product pages of every kind are read in code, to see what the feed
 * does not carry: one of each category, of each type the store sells, and of each set of choices a
 * product is sold with (ten necklaces show nothing of what a ring asks). Each page is read for its
 * other versions linked as products of their own (PageSignals::siblings), a subscription (Shopify's
 * selling plans, an app such as Recharge, WooCommerce's subscription products) and choices asked on
 * the page only. Gently: a pause between pages, and a store that struggles is left alone (StoreRest).
 * No model.
 */
final class SamplePages
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.sample_pages';

    private const TIMEOUT_SECONDS = 20;

    /** Examples kept of each finding, for the operator. */
    private const EXAMPLES = 5;

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
        if (! Features::enabled('catalog.sample_pages', $shopId) || StoreRest::resting($shopId)) {
            return null;
        }

        return $this->runs->track(agent: self::AGENT, action: self::ACTION, shopId: $shopId, trigger: $trigger, work: function (RunContext $run) use ($shopId): void {
            $this->tenant->run($shopId, function () use ($run, $shopId): void {
                $shopify = StoreConnection::query()->value('platform') === 'shopify';
                [$picked, $kinds] = self::pick(CatalogProduct::query()->active()->whereNotNull('url')->with('categories:id')->orderBy('id')->get(), (int) Settings::get('catalog.sample_pages_max', $shopId));
                $delay = (int) Settings::get('catalog.page_choices_delay_ms', $shopId);
                $found = ['read' => 0, 'failed' => 0, 'siblings' => [], 'plans' => [], 'apps' => [], 'fields' => []];
                $down = 0;

                foreach ($picked->values() as $i => $product) {
                    if ($i > 0) {
                        ($this->pause)($delay);
                    }

                    $page = $this->get((string) $product->url, 'text/html');
                    if ($page === null || ! $page->successful()) {
                        $found['failed']++;
                        $down = StoreRest::struggling($page) ? $down + 1 : 0;
                        if ($down >= StoreRest::TIMES) {
                            StoreRest::rest($shopId);

                            break;
                        }

                        continue;
                    }

                    $down = 0;
                    $found['read']++;
                    $html = (string) $page->body();
                    $title = (string) $product->title;

                    if (($siblings = PageSignals::siblings($html, (string) $product->url)) !== []) {
                        $found['siblings'][$title] = array_slice(array_column($siblings, 'label'), 0, 8);
                    }
                    foreach (PageSignals::subscriptionApps($html) as $app) {
                        $found['apps'][$app] = true;
                    }
                    foreach (PageSignals::pageFields($html) as $field) {
                        $found['fields'][$field][] = $title;
                    }
                    if ($shopify && ($json = $this->get(strtok((string) $product->url, '?#').'.js', 'application/json')) !== null && $json->successful()) {
                        $plans = PageSignals::shopifyPlans((array) $json->json());
                        if ($plans['plans'] > 0) {
                            $found['plans'][$title] = $plans['required'];
                        }
                    }
                }

                // WooCommerce says a product is a subscription in its type: every one counted, not only the pages read.
                $wooSubscriptions = $shopify ? 0 : CatalogProduct::query()->active()->whereIn('type', ['subscription', 'variable-subscription'])->count();

                $run->output([
                    'sampled' => $picked->count(),
                    'kinds' => $kinds,
                    'read' => $found['read'],
                    'failed' => $found['failed'],
                    'siblings' => ['products' => count($found['siblings']), 'examples' => array_slice($found['siblings'], 0, self::EXAMPLES, true)],
                    'subscriptions' => [
                        'apps' => array_keys($found['apps']),
                        'products_with_plans' => count($found['plans']),
                        'plan_required' => count(array_filter($found['plans'])),
                        'woocommerce_products' => $wooSubscriptions,
                        'examples' => array_slice(array_keys($found['plans']), 0, self::EXAMPLES),
                    ],
                    'page_fields' => array_map(fn (array $titles): array => ['products' => count($titles), 'examples' => array_slice($titles, 0, 3)], $found['fields']),
                ])->summary('catalog::runs.pages_sampled', [
                    'read' => (string) $found['read'],
                    'kinds' => (string) $kinds,
                    'siblings' => (string) count($found['siblings']),
                    'subscriptions' => (string) (count($found['plans']) + $wooSubscriptions),
                    'fields' => (string) count($found['fields']),
                ]);
            });
        });
    }

    /**
     * The pages to read: every kind at least once, the fewest pages that cover them. A product's kinds
     * are its categories, its type in the store (simple, variable, a subscription), its product type
     * (Shopify) and the set of choices it is sold with ("מידה", "צבע+מידה"). In stock first among equals.
     *
     * @param  Collection<int, CatalogProduct>  $products
     * @return array{0: Collection<int, CatalogProduct>, 1: int} the products, and how many kinds they cover
     */
    public static function pick(Collection $products, int $max): array
    {
        $marks = [];
        foreach ($products as $product) {
            $choices = array_map(fn (array $a): string => mb_strtolower(trim($a['name'])), array_filter($product->storeAttributes(), fn (array $a): bool => $a['for_variations']));
            sort($choices);
            $marks[$product->id] = array_values(array_unique(array_filter([
                'type:'.$product->type,
                'choices:'.implode('+', $choices),
                ($kind = trim((string) ($product->payload['product_type'] ?? ''))) === '' ? null : 'kind:'.mb_strtolower($kind),
                ...$product->categories->map(fn ($c): string => 'category:'.$c->id)->all(),
            ])));
        }

        $all = array_unique(array_merge(...array_values($marks ?: [[]])));
        $open = array_fill_keys($all, true);
        $picked = [];

        while ($open !== [] && count($picked) < $max) {
            $best = null;
            $bestGain = 0;
            foreach ($products as $product) {
                if (isset($picked[$product->id])) {
                    continue;
                }
                $gain = count(array_intersect_key(array_flip($marks[$product->id]), $open)) * 2 + ($product->in_stock ? 1 : 0);
                if ($gain > $bestGain) {
                    [$best, $bestGain] = [$product, $gain];
                }
            }
            if ($best === null || $bestGain < 2) {
                break;
            }
            $picked[$best->id] = $best;
            $open = array_diff_key($open, array_flip($marks[$best->id]));
        }

        return [collect(array_values($picked)), count($all) - count($open)];
    }

    private function get(string $url, string $accept): ?Response
    {
        try {
            return Http::withHeaders(['User-Agent' => 'LetsSearch/1.0 (+https://agents.lets.co.il)', 'Accept' => $accept])->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable) {
            return null;
        }
    }
}
