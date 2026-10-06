<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsOrderImport;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * One page of a store's past orders, sent once by the plugin so purchases can be learned from
 * before Rega was installed.
 *
 * Each order has the same shape as a live one and nothing more: a keyed hash of the order
 * number, totals, product IDs, quantities and line totals. Never a customer, never a visitor.
 * Sending a page twice stores nothing twice. The plugin says which page is the first (to
 * restart the count) and which is the last, and how many it expects, so the panel can show
 * how far it has got.
 */
final class RecordOrderHistory
{
    public const MAX_ORDERS = 100;

    public function __construct(
        private readonly RecordOrder $record,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  {orders: list<order>, first?: bool, last?: bool, expected?: int}
     * @return array{received: int, stored: int, problems: list<string>}
     */
    public function handle(StoreConnection $connection, array $payload): array
    {
        $validator = Validator::make($payload, [
            'orders' => ['present', 'array', 'max:'.self::MAX_ORDERS],
            'orders.*' => ['array'],
            'first' => ['sometimes', 'boolean'],
            'last' => ['sometimes', 'boolean'],
            'expected' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        if ($validator->fails()) {
            return ['received' => 0, 'stored' => 0, 'problems' => array_keys($validator->errors()->toArray())];
        }

        $stored = 0;
        $refused = 0;
        $dates = [];

        foreach ($payload['orders'] as $order) {
            $result = $this->record->handle($connection, $order, history: true);

            if ($result['problems'] !== []) {
                $refused++;

                continue;
            }

            $stored += $result['stored'] ? 1 : 0;
            $dates[] = (int) $order['ordered_at'];
        }

        $this->tenant->run($connection->shop_id, function () use ($connection, $payload, $stored, $refused, $dates): void {
            $import = AnalyticsOrderImport::query()->firstOrNew(['shop_id' => $connection->shop_id]);

            if (($payload['first'] ?? false) || ! $import->exists) {
                $import->fill([
                    'expected' => null, 'received' => 0, 'stored' => 0, 'refused' => 0,
                    'oldest_ordered_at' => null, 'newest_ordered_at' => null,
                    'started_at' => now(), 'finished_at' => null,
                ]);
            }

            if (array_key_exists('expected', $payload)) {
                $import->expected = $payload['expected'] === null ? null : (int) $payload['expected'];
            }

            $import->received += count($payload['orders']);
            $import->stored += $stored;
            $import->refused += $refused;

            if ($dates !== []) {
                $oldest = Carbon::createFromTimestamp(min($dates));
                $newest = Carbon::createFromTimestamp(max($dates));
                $import->oldest_ordered_at = $import->oldest_ordered_at === null || $oldest->lt($import->oldest_ordered_at) ? $oldest : $import->oldest_ordered_at;
                $import->newest_ordered_at = $import->newest_ordered_at === null || $newest->gt($import->newest_ordered_at) ? $newest : $import->newest_ordered_at;
            }

            if ($payload['last'] ?? false) {
                $import->finished_at = now();
            }

            $import->save();
        });

        return ['received' => count($payload['orders']), 'stored' => $stored, 'problems' => []];
    }
}
