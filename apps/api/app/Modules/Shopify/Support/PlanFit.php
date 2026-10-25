<?php

namespace App\Modules\Shopify\Support;

use App\Core\Tenancy\TenantContext;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * Which plans fit a store, for the plans page: its size (the products on sale, as Shopify counts them now, and its
 * pages, as the scan read them or Shopify counts them) and what it used this month (AI answers, photo searches),
 * against each plan's limits. The smallest plan none of them outgrows is the one that fits. Code only, the same for
 * every store; a count Shopify does not give is left out rather than guessed.
 */
final class PlanFit
{
    /** The store's size is read again at most this often: the page is opened more than once while choosing. */
    private const KEEP_MINUTES = 30;

    /** The month's meters, as the Assistant and Search modules count them (MonthlyUsage). */
    private const METERS = ['answers' => 'assistant.answers_per_month', 'photos' => 'search.photos_per_month'];

    private const COUNTS = <<<'GRAPHQL'
        query StoreSize {
          productsCount(query: "status:active AND published_status:published", limit: null) { count }
          pagesCount(limit: null) { count }
        }
        GRAPHQL;

    public function __construct(
        private readonly ShopifyApi $api,
        private readonly TenantContext $tenant,
        private readonly MonthlyUsage $usage,
    ) {}

    /** @return array{products: int|null, pages: int|null, answers: int, photos: int} */
    public function store(ShopifyInstall $install): array
    {
        $size = Cache::remember('shopify:plan-fit:'.$install->id, now()->addMinutes(self::KEEP_MINUTES), function () use ($install): array {
            try {
                $data = $this->api->query($install, self::COUNTS);
                $products = isset($data['productsCount']['count']) ? (int) $data['productsCount']['count'] : null;
                $pages = isset($data['pagesCount']['count']) ? (int) $data['pagesCount']['count'] : null;
            } catch (StoreFeedUnavailable) {
                $products = $pages = null;
            }
            // what the scan read, when there is a shop yet: pages and posts both count toward a plan's pages
            [$scanned, $read] = $install->shop_id === null ? [0, 0] : $this->tenant->run((string) $install->shop_id, fn (): array => [
                CatalogProduct::query()->active()->count(),
                CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->count(),
            ]);

            return ['products' => $products ?? ($scanned > 0 ? $scanned : null), 'pages' => max($pages ?? 0, $read) ?: null];
        });

        return $size + array_map(fn (string $key): int => $install->shop_id === null ? 0 : $this->usage->used((string) $install->shop_id, $key), array_combine(array_keys(self::METERS), array_keys(self::METERS)));
    }

    /**
     * Each plan's limits the store outgrows, and the plan that fits: the first (the plans in their order) it outgrows
     * none of. None fits a store bigger than every plan.
     *
     * @param  iterable<Plan>  $plans  in the order they are offered
     * @param  array{products: int|null, pages: int|null, answers: int, photos: int}  $store
     * @return array{fits: string|null, short: array<string, list<array{what: string, limit: int, has: int}>>}
     */
    public static function choose(iterable $plans, array $store): array
    {
        $fits = null;
        $short = [];

        foreach ($plans as $plan) {
            $limits = [
                'products' => $plan->ceiling('catalog.max_products'),
                'pages' => $plan->ceiling('catalog.max_content_items'),
                'answers' => $plan->ceiling(self::METERS['answers']),
                'photos' => $plan->includes('search.photos') ? $plan->ceiling(self::METERS['photos']) : 0,
            ];
            $over = [];
            foreach ($limits as $what => $limit) {
                $has = $store[$what] ?? null;
                if (is_numeric($limit) && $has !== null && $has > (int) $limit) {
                    $over[] = ['what' => $what, 'limit' => (int) $limit, 'has' => (int) $has];
                }
            }
            $short[$plan->code] = $over;
            $fits ??= $over === [] ? $plan->code : null;
        }

        return ['fits' => $fits, 'short' => $short];
    }
}
