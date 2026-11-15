<?php

namespace App\Modules\Shopify\Jobs;

use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The work of a privacy webhook Shopify sends every app (customers/data_request, customers/redact, shop/redact), done in
 * the background: the webhook is answered 200 at once. One shop/redact took 6,009 ms and timed out on Shopify's first try
 * (14.11), deleting the shop and all it holds while Shopify waited.
 */
final class HandleComplianceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> seconds before each try after the first */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  array{email?: string, id?: string, phone?: string}  $customer
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $shopId,
        public readonly array $customer = [],
        public readonly string $requestId = '',
    ) {}

    public function handle(): void
    {
        match ($this->topic) {
            'customers/data_request', 'customers/redact' => event(new ShopperDataRequested(
                $this->shopId,
                $this->topic === 'customers/redact' ? 'redact' : 'report',
                mb_strtolower((string) ($this->customer['email'] ?? '')),
                (string) ($this->customer['id'] ?? ''),
                (string) ($this->customer['phone'] ?? ''),
                $this->requestId,
            )),
            // 48 hours after the app was removed: the shop and all it holds go, the kernel's tables and its files too
            // (deleted as a model, so Shop::deleted cleans what has no foreign key).
            'shop/redact' => Shop::query()->whereKey($this->shopId)->first()?->delete(),
            default => null,
        };
    }
}
