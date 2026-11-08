<?php

namespace App\Modules\Analytics\Support;

use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\ShopperVisits;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The visits on the Shoppers screen: how many, where the ones with a search came from, and each visit told as a short
 * story (what they searched, what they saw, how it ended). Read from analytics_visits and their steps; a preview is no
 * visit. Runs inside the shop's TenantContext. No model.
 */
final class ShoppersVisits implements ShopperVisits
{
    /** A source needs this many visits before "they search the most" is said of it. */
    private const ENOUGH = 20;

    /** Sources shown; the rest are not drawn (their share stays out of the bars). */
    private const SOURCES_SHOWN = 5;

    /** Steps in one story; a longer visit keeps its start and how it ended. */
    private const STEPS = 5;

    /** Orders read for "bought"; a store with more reads its latest. */
    private const ORDERS_READ = 10000;

    public function numbers(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        return [
            'visits' => $this->visits($since, $until)->count(),
            'searched' => $this->searched($this->visits($since, $until))->count(),
        ];
    }

    public function sources(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $searched = $this->searched($this->visits($since, $until))->toBase()
            ->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source')->map(fn ($n): int => (int) $n)->all();
        $total = array_sum($searched);
        uksort($searched, fn (string $a, string $b): int => [$searched[$b], $a] <=> [$searched[$a], $b]);

        $rows = [];
        foreach (array_slice($searched, 0, self::SOURCES_SHOWN, true) as $source => $n) {
            $rows[] = ['source' => (string) $source, 'n' => $n, 'pct' => $total > 0 ? (int) round(100 * $n / $total) : 0];
        }

        // who searches most: the share of each source's visits that had a search, among sources with enough visits
        $all = $this->visits($since, $until)->toBase()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source')->map(fn ($n): int => (int) $n)->all();
        $rate = [];
        foreach ($all as $source => $n) {
            if ($n >= self::ENOUGH && in_array($source, AnalyticsVisit::SOURCES, true) && ! in_array($source, ['direct', 'other'], true)) {
                $rate[(string) $source] = ($searched[$source] ?? 0) / $n;
            }
        }
        arsort($rate);

        $campaign = $this->visits($since, $until)->whereNotNull('campaign')->where('campaign', '!=', '')->toBase()
            ->selectRaw('campaign, count(*) as n')->groupBy('campaign')->orderByDesc('n')->orderBy('campaign')->first();

        return [
            'rows' => $rows,
            'searchiest' => count($rows) >= 2 && $rate !== [] && reset($rate) > 0 ? (string) array_key_first($rate) : null,
            'campaign' => $campaign === null ? null : ['name' => (string) $campaign->campaign, 'visits' => (int) $campaign->n],
        ];
    }

    public function stories(Shop $shop, CarbonInterface $since, CarbonInterface $until, bool $plain, int $limit, array $filter = []): array
    {
        $query = $this->visits($since, $until);
        if (! $plain) {
            $this->searched($query);
        }
        $this->narrow($query, $filter);
        $visits = $query->latest('started_at')->orderByDesc('id')->limit($limit)->get();
        if ($visits->isEmpty()) {
            return [];
        }

        $steps = AnalyticsVisitStep::query()->whereIn('visit_id', $visits->pluck('id'))->where('kind', '!=', 'open')
            ->orderBy('at')->orderBy('id')->limit(3000)->get(['visit_id', 'kind', 'page_type', 'item', 'label', 'number'])->groupBy('visit_id');
        $products = CatalogProduct::query()->whereIn('external_id', $steps->flatten()->pluck('item')->filter()->unique()->map(fn ($id): string => (string) $id)->values())
            ->get(['external_id', 'title', 'image_url', 'price'])->keyBy('external_id');
        $currency = (string) ($shop->currency ?: 'ILS');

        return $visits->map(function (AnalyticsVisit $visit) use ($steps, $products, $currency): array {
            $own = $steps->get($visit->id, collect())->all();

            return [
                'id' => (int) $visit->id,
                'who' => $visit->visit_number > 1 ? 'returning' : (in_array($visit->source, AnalyticsVisit::SOURCES, true) ? (string) $visit->source : 'other'),
                'device' => self::device($visit),
                'minutes' => max(1, (int) round($visit->duration() / 60)),
                'at' => $visit->started_at,
                'steps' => $this->story($visit, $own, $products),
                'end' => $this->end($visit, $own, $products, $currency),
            ];
        })->all();
    }

    public function visitOf(Shop $shop, string $visitor, CarbonInterface $at): ?int
    {
        $mine = fn (): Builder => AnalyticsVisit::query()->where('preview', false)->where('visitor_hash', $visitor);
        $id = $mine()->where('started_at', '<=', $at->copy()->addMinutes(2))->where('started_at', '>=', $at->copy()->subDay())
            ->latest('started_at')->orderByDesc('id')->value('id')
            ?? $mine()->where('started_at', '>', $at)->where('started_at', '<=', $at->copy()->addDay())->oldest('started_at')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The story list narrowed as the screen asks: by the shopper's words or a product they met, where they came from
     * (as the story names it), the device, and how it ended.
     *
     * @param  Builder<AnalyticsVisit>  $query
     * @param  array{q?: string, who?: string, device?: string, ended?: string}  $filter
     */
    private function narrow(Builder $query, array $filter): void
    {
        $q = mb_strtolower(trim((string) ($filter['q'] ?? '')));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $items = CatalogProduct::query()->whereRaw('lower(title) like ?', [$like])->limit(200)->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
            $query->where(function (Builder $w) use ($like, $items): void {
                $w->where('words', 'like', $like);
                if ($items !== []) {
                    $w->orWhereIn('id', AnalyticsVisitStep::query()->select('visit_id')->whereIn('item', $items));
                }
            });
        }

        $named = array_values(array_diff(AnalyticsVisit::SOURCES, ['other']));
        $who = (string) ($filter['who'] ?? '');
        match (true) {
            $who === 'returning' => $query->where('visit_number', '>', 1),
            in_array($who, $named, true) => $query->where('visit_number', '<=', 1)->where('source', $who),
            $who === 'other' => $query->where('visit_number', '<=', 1)->where(fn (Builder $w) => $w->whereNull('source')->orWhereNotIn('source', $named)),
            default => null,
        };

        match ((string) ($filter['device'] ?? '')) {
            'phone' => $query->whereNotNull('device')->whereNotIn('device', ['computer', 'tablet']),
            'tablet' => $query->where('device', 'tablet'),
            'computer' => $query->where(fn (Builder $w) => $w->whereNull('device')->orWhere('device', 'computer')),
            default => null,
        };

        // as the story's end reads it: an order first, then the cart, then what was saved
        $noOrder = fn (Builder $w) => $w->whereNull('order_total')->orWhere('order_total', '<=', 0);
        match ((string) ($filter['ended'] ?? '')) {
            'order' => $query->where('order_total', '>', 0),
            'cart' => $query->where($noOrder)->where('did', 'like', '%"cart"%'),
            'saved' => $query->where($noOrder)->where('did', 'not like', '%"cart"%')->where('did', 'like', '%"heart"%'),
            'none' => $query->where($noOrder)->where(fn (Builder $w) => $w->whereNull('did')->orWhere(fn (Builder $x) => $x->where('did', 'not like', '%"cart"%')->where('did', 'not like', '%"heart"%'))),
            default => null,
        };
    }

    public function bought(Shop $shop, array $ids, CarbonInterface $since, CarbonInterface $until): array
    {
        $wanted = array_fill_keys(array_map('strval', $ids), 0);
        if ($wanted === []) {
            return [];
        }
        $orders = AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)
            ->where('ordered_at', '>=', $since)->where('ordered_at', '<', $until)
            ->latest('ordered_at')->limit(self::ORDERS_READ)->get(['id', 'items']);
        foreach ($orders as $order) {
            foreach (array_unique(array_map(fn ($item): string => is_array($item) ? (string) ($item['product_id'] ?? '') : '', (array) $order->items)) as $id) {
                if (isset($wanted[$id])) {
                    $wanted[$id]++;
                }
            }
        }

        return $wanted;
    }

    /** @return Builder<AnalyticsVisit> */
    private function visits(CarbonInterface $since, CarbonInterface $until): Builder
    {
        return AnalyticsVisit::query()->where('preview', false)->where('started_at', '>=', $since)->where('started_at', '<', $until);
    }

    /**
     * @param  Builder<AnalyticsVisit>  $query
     * @return Builder<AnalyticsVisit>
     */
    private function searched(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('did', 'like', '%"search"%')->orWhere('did', 'like', '%"photo"%')->orWhere('did', 'like', '%"ask"%'));
    }

    private static function device(AnalyticsVisit $visit): string
    {
        return match (true) {
            $visit->device === 'computer' || $visit->device === null => 'computer',
            $visit->device === 'tablet' => 'tablet',
            $visit->os === 'iOS' => 'iphone',
            $visit->os === 'Android' => 'android',
            default => 'phone',
        };
    }

    /**
     * The visit as a few steps: a run of product views is one step, the views before the first search are left out of
     * a visit with one, and a visit with no search is the pages it went through.
     *
     * @param  list<AnalyticsVisitStep>  $steps
     * @param  Collection<string, CatalogProduct>  $products
     * @return list<array{kind: string, text: string|null, n: int, picture: string|null}>
     */
    private function story(AnalyticsVisit $visit, array $steps, $products): array
    {
        $searched = collect($steps)->contains(fn (AnalyticsVisitStep $s): bool => in_array($s->kind, ['search', 'photo', 'ask'], true));
        $out = [];
        $started = ! $searched;

        foreach ($steps as $step) {
            $kind = (string) $step->kind;
            $started = $started || in_array($kind, ['search', 'photo', 'ask'], true);
            if (! $started) {
                continue;
            }
            $product = $step->item !== null ? $products->get((string) $step->item) : null;
            $last = $out === [] ? null : count($out) - 1;

            if ($kind === 'view') {
                if ($product === null) {
                    continue;
                }
                if ($last !== null && in_array($out[$last]['kind'], ['view', 'views'], true)) {
                    if ($out[$last]['item'] !== (string) $step->item) {
                        $out[$last]['kind'] = 'views';
                        $out[$last]['n']++;
                        $out[$last]['item'] = (string) $step->item;
                    }

                    continue;
                }
                $out[] = ['kind' => 'view', 'text' => (string) $product->title, 'n' => 1, 'picture' => $product->image_url, 'item' => (string) $step->item];

                continue;
            }
            if (in_array($kind, ['heart', 'cart'], true) && $last !== null && $out[$last]['kind'] === $kind) {
                $out[$last]['n']++;

                continue;
            }
            $out[] = match ($kind) {
                'search', 'ask' => ['kind' => $kind, 'text' => trim((string) $step->label) ?: null, 'n' => 1, 'picture' => null, 'item' => null],
                'photo' => ['kind' => 'photo', 'text' => null, 'n' => 1, 'picture' => null, 'item' => null],
                'heart', 'cart' => ['kind' => $kind, 'text' => $product?->title, 'n' => 1, 'picture' => $product?->image_url, 'item' => null],
                'order' => ['kind' => 'order', 'text' => null, 'n' => 1, 'picture' => null, 'item' => null],
                'lead' => ['kind' => 'lead', 'text' => null, 'n' => 1, 'picture' => null, 'item' => null],
                default => null,
            };
            $out = array_values(array_filter($out));
        }

        if (! $searched && ! collect($out)->contains(fn (array $s): bool => in_array($s['kind'], ['heart', 'cart', 'order', 'lead'], true))) {
            $out = [['kind' => 'browse', 'text' => null, 'n' => max(1, (int) $visit->pages), 'picture' => null, 'item' => null]];
        }
        if (count($out) > self::STEPS) {
            $out = [...array_slice($out, 0, self::STEPS - 1), $out[count($out) - 1]];
        }

        return array_map(fn (array $s): array => ['kind' => $s['kind'], 'text' => $s['text'], 'n' => $s['n'], 'picture' => $s['picture']], $out);
    }

    /**
     * How it ended: an order and its total, what went to the cart (its worth, when the prices are known), or what was
     * saved for later.
     *
     * @param  list<AnalyticsVisitStep>  $steps
     * @param  Collection<string, CatalogProduct>  $products
     * @return array{kind: string, value: string}|null
     */
    private function end(AnalyticsVisit $visit, array $steps, $products, string $currency): ?array
    {
        if ((float) $visit->order_total > 0) {
            return ['kind' => 'order', 'value' => Money::total((float) $visit->order_total, $currency)];
        }
        $carts = array_values(array_filter($steps, fn (AnalyticsVisitStep $s): bool => $s->kind === 'cart'));
        if ($carts !== []) {
            $worth = 0.0;
            foreach ($carts as $cart) {
                $price = $cart->item !== null ? $products->get((string) $cart->item)?->price : null;
                $worth += $price === null ? 0 : (float) $price * max(1, (int) $cart->number);
            }

            return ['kind' => 'cart', 'value' => $worth > 0 ? Money::total($worth, $currency) : number_format(count($carts))];
        }
        $hearts = count(array_filter($steps, fn (AnalyticsVisitStep $s): bool => $s->kind === 'heart'));

        return $hearts > 0 ? ['kind' => 'saved', 'value' => number_format($hearts)] : null;
    }
}
