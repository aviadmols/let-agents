<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Analytics\Models\AnalyticsOrder;
use Illuminate\Support\Collection;

/**
 * What a shop's orders say about its products, counted in code: how many orders each product
 * was in, and how often two products were in the same order. Live orders and the history the
 * plugin sent once count alike — both are things shoppers really bought.
 *
 * Lift is how much more often two products share an order than chance would put them there:
 * together(A, B) × orders ÷ (orders(A) × orders(B)). Two best-sellers share many orders by
 * chance alone; a lift well above 1 is what says they belong together.
 *
 * An order is a list of product IDs and nothing else. Nothing here reads a shopper.
 */
final class Purchases
{
    /** An order with more lines than this is a restock, not a decision about what goes together. */
    public const MAX_LINES = 12;

    /** @var array<string, int> external product ID => orders it was in */
    private array $orders = [];

    /** @var array<string, array<string, int>> external ID => partner external ID => orders together */
    private array $together = [];

    private int $baskets = 0;

    private int $total = 0;

    /** Reads the shop's orders inside `retrieval.purchase_window_days`. Runs in the shop's tenant context. */
    public static function read(string $shopId): self
    {
        $purchases = new self;
        $days = (int) Settings::get('retrieval.purchase_window_days', $shopId);

        AnalyticsOrder::query()
            ->where('ordered_at', '>=', now()->subDays($days))
            ->select(['id', 'items'])
            ->chunkById(500, function (Collection $chunk) use ($purchases): void {
                foreach ($chunk as $order) {
                    $purchases->add(array_values(array_unique(array_map(
                        fn ($item): string => (string) ($item['product_id'] ?? ''),
                        (array) $order->items,
                    ))));
                }
            });

        return $purchases;
    }

    /** @param list<string> $products external IDs in one order */
    public function add(array $products): void
    {
        $products = array_values(array_filter($products, fn (string $id): bool => $id !== ''));

        if ($products === []) {
            return;
        }

        $this->total++;

        foreach ($products as $product) {
            $this->orders[$product] = ($this->orders[$product] ?? 0) + 1;
        }

        if (count($products) < 2 || count($products) > self::MAX_LINES) {
            return;
        }

        $this->baskets++;

        foreach ($products as $one) {
            foreach ($products as $other) {
                if ($one !== $other) {
                    $this->together[$one][$other] = ($this->together[$one][$other] ?? 0) + 1;
                }
            }
        }
    }

    public function totalOrders(): int
    {
        return $this->total;
    }

    /** Orders with two to MAX_LINES products: the ones that say anything about pairs. */
    public function baskets(): int
    {
        return $this->baskets;
    }

    public function ordersOf(string $externalId): int
    {
        return $this->orders[$externalId] ?? 0;
    }

    /** @return array<string, int> external ID => orders it was in, best-selling first */
    public function bestSellers(): array
    {
        $orders = $this->orders;
        arsort($orders);

        return $orders;
    }

    /**
     * Products bought in the same order as this one, most often first.
     *
     * @return list<array{external_id: string, together: int, lift: float}>
     */
    public function partnersOf(string $externalId, int $limit = 20): array
    {
        $partners = [];

        foreach ($this->together[$externalId] ?? [] as $other => $count) {
            $partners[] = ['external_id' => (string) $other, 'together' => $count, 'lift' => $this->lift($externalId, (string) $other)];
        }

        usort($partners, fn (array $a, array $b): int => [$b['together'], $b['lift']] <=> [$a['together'], $a['lift']]);

        return array_slice($partners, 0, $limit);
    }

    public function lift(string $one, string $other): float
    {
        $together = $this->together[$one][$other] ?? 0;
        $denominator = $this->ordersOf($one) * $this->ordersOf($other);

        return $together === 0 || $denominator === 0 ? 0.0 : round($together * $this->total / $denominator, 2);
    }
}
