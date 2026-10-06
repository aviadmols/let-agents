<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Stores an order the store's plugin reported, and works out what Rega had to do with it:
 *
 *   attributed   products in the order the same visitor added to the cart from the widget
 *   assisted     the visitor opened or used the widget before ordering
 *
 * Both within the shop's attribution window. The order carries no customer data: a hashed
 * order number, totals, product IDs and quantities, and the anonymous visitor ID.
 *
 * A past order ($history) is stored as it is, with no visitor and nothing attributed: Rega was
 * not there when it was placed. It is what purchases are learned from.
 */
final class RecordOrder
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stored: bool, problems: list<string>, assisted?: bool}
     */
    public function handle(StoreConnection $connection, array $payload, bool $history = false): array
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

        return $this->tenant->run($shopId, function () use ($payload, $shopId, $history): array {
            $orderedAt = Carbon::createFromTimestamp((int) $payload['ordered_at']);
            $visitor = isset($payload['vid']) ? RecordBeacon::visitorHash($shopId, (string) $payload['vid']) : null;
            $windowStart = $orderedAt->copy()->subDays((int) Settings::get('analytics.attribution_days', $shopId));

            $attributed = [];
            $assisted = false;

            if ($visitor !== null) {
                $events = AnalyticsEvent::query()
                    ->where('visitor_hash', $visitor)
                    ->whereBetween('occurred_at', [$windowStart, $orderedAt->copy()->addMinutes(10)])
                    ->whereIn('type', ['open', 'click', 'add_to_cart'])
                    ->get(['type', 'source', 'result', 'item_external_id']);

                $assisted = $events->isNotEmpty();
                $addedFromWidget = $events
                    ->where('type', 'add_to_cart')->where('source', 'widget')->where('result', 'added')
                    ->pluck('item_external_id')->filter()->unique()->all();

                foreach ($payload['items'] as $item) {
                    if (in_array((string) $item['product_id'], $addedFromWidget, true)) {
                        $attributed[] = $item;
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
                    'ordered_at' => $orderedAt,
                ],
            );

            return ['stored' => $order->wasRecentlyCreated, 'problems' => [], 'assisted' => $order->assisted];
        });
    }
}
