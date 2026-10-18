<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Stores an order the store's plugin or the Shopify pixel reported, and works out what Let Agents
 * had to do with it:
 *
 *   attributed   products in the order the same visitor added to the cart from the widget
 *   search       products in the order the same visitor added to the cart from the store's search
 *                (its results or the assistant's picks), kept apart from the widget's
 *   assisted     the visitor opened or used the widget before ordering
 *
 * All within the shop's attribution window. The order carries no customer data: a hashed
 * order number, totals, product IDs and quantities, and the anonymous visitor ID.
 *
 * A past order ($history) is stored as it is, with no visitor and nothing attributed: Let Agents was
 * not there when it was placed. It is what purchases are learned from.
 */
final class RecordOrder
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stored: bool, problems: list<string>, assisted?: bool}
     */
    public function handle(StoreConnection $connection, array $payload, bool $history = false, string $reportedBy = AnalyticsOrder::REPORTED_BY_PLUGIN): array
    {
        if ($history) {
            unset($payload['vid']);
        }

        $validator = Validator::make($payload, [
            'order_ref' => ['required', 'string', 'regex:/^[a-f0-9]{16,64}$/'],
            'total' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'currency' => ['required', 'string', 'size:3'],
            'ordered_at' => ['required', 'integer'],
            'vid' => ['nullable', 'string', 'regex:/^anon-[A-Za-z0-9_-]{16,64}$/'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.total' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return ['stored' => false, 'problems' => array_keys($validator->errors()->toArray())];
        }

        $shopId = $connection->shop_id;

        return $this->tenant->run($shopId, function () use ($payload, $shopId, $history, $reportedBy): array {
            $orderedAt = Carbon::createFromTimestamp((int) $payload['ordered_at']);
            $visitor = isset($payload['vid']) ? RecordBeacon::visitorHash($shopId, (string) $payload['vid']) : null;
            $windowStart = $orderedAt->copy()->subDays((int) Settings::get('analytics.attribution_days', $shopId));

            $attributed = [];
            $fromSearch = [];
            $assisted = false;

            if ($visitor !== null) {
                $events = AnalyticsEvent::query()
                    ->where('visitor_hash', $visitor)
                    ->whereBetween('occurred_at', [$windowStart, $orderedAt->copy()->addMinutes(10)])
                    ->whereIn('type', ['open', 'click', 'add_to_cart'])
                    ->get(['type', 'source', 'result', 'item_external_id']);

                // The widget's: what the search did is counted on its own, below.
                $assisted = $events->where('source', '!==', AnalyticsEvent::SOURCE_SEARCH)->isNotEmpty();
                $added = fn (string $source): array => $events
                    ->where('type', 'add_to_cart')->where('source', $source)->where('result', 'added')
                    ->pluck('item_external_id')->filter()->map(fn ($id): string => (string) $id)->unique()->all();
                $addedFromWidget = $added('widget');
                $addedFromSearch = $added(AnalyticsEvent::SOURCE_SEARCH);

                foreach ($payload['items'] as $item) {
                    if (in_array((string) $item['product_id'], $addedFromWidget, true)) {
                        $attributed[] = $item;
                    }
                    if (in_array((string) $item['product_id'], $addedFromSearch, true)) {
                        $fromSearch[] = $item;
                    }
                }
            }

            $order = AnalyticsOrder::query()->firstOrCreate(
                ['shop_id' => $shopId, 'order_ref' => $payload['order_ref']],
                [
                    'source' => $history ? AnalyticsOrder::SOURCE_HISTORY : AnalyticsOrder::SOURCE_LIVE,
                    'total' => round((float) $payload['total'], 2),
                    'currency' => strtoupper((string) $payload['currency']),
                    'items_count' => array_sum(array_column($payload['items'], 'quantity')),
                    'items' => array_values(array_map(fn (array $i): array => [
                        'product_id' => (string) $i['product_id'],
                        'quantity' => (int) $i['quantity'],
                        'total' => round((float) $i['total'], 2),
                    ], $payload['items'])),
                    'visitor_hash' => $visitor,
                    'assisted' => $assisted || $attributed !== [],
                    'attributed_total' => round(array_sum(array_map(fn (array $i): float => (float) $i['total'], $attributed)), 2),
                    'attributed_items' => array_values(array_map(fn (array $i): string => (string) $i['product_id'], $attributed)),
                    'search_total' => round(array_sum(array_map(fn (array $i): float => (float) $i['total'], $fromSearch)), 2),
                    'search_items' => $fromSearch === [] ? null : array_values(array_map(fn (array $i): string => (string) $i['product_id'], $fromSearch)),
                    'reported_by' => $reportedBy,
                    'ordered_at' => $orderedAt,
                ],
            );

            if ($order->wasRecentlyCreated && ! $history && $visitor !== null) {
                self::onVisit($visitor, $order, $windowStart);
            }

            return ['stored' => $order->wasRecentlyCreated, 'problems' => [], 'assisted' => $order->assisted];
        });
    }

    /** The purchase, as the last step of the visitor's latest visit before it (the sessions screen). */
    private static function onVisit(string $visitor, AnalyticsOrder $order, Carbon $since): void
    {
        $visit = AnalyticsVisit::query()->where('visitor_hash', $visitor)
            ->where('started_at', '>=', $since)->where('started_at', '<=', $order->ordered_at->copy()->addMinutes(10))
            ->latest('started_at')->first();

        if ($visit === null) {
            return;
        }

        AnalyticsVisitStep::query()->insertOrIgnore([[
            'shop_id' => $visit->shop_id, 'visit_id' => $visit->id, 'step_id' => 'order-'.substr(hash('sha256', (string) $order->order_ref), 0, 20),
            'kind' => 'order', 'at' => $order->ordered_at, 'number' => (int) $order->items_count, 'value' => $order->total,
            'path' => null, 'page_type' => null, 'item' => null, 'label' => null,
        ]]);

        $visit->forceFill([
            'did' => array_values(array_unique([...(array) $visit->did, 'order'])),
            'order_total' => round((float) $visit->order_total + (float) $order->total, 2),
            'steps' => $visit->steps + 1,
            'last_at' => $order->ordered_at->gt($visit->last_at) ? $order->ordered_at : $visit->last_at,
        ])->save();
    }
}
