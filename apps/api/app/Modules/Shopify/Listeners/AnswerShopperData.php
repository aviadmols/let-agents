<?php

namespace App\Modules\Shopify\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Shopify\Actions\ReportShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Models\ShopperDataReport;

/**
 * A shopper's privacy request, passed on by Shopify: asked what the store keeps, the report is made for the owners
 * (ReportShopperData); asked to be erased, the reports made for them earlier go too, by their Shopify customer id
 * or their email.
 */
final class AnswerShopperData
{
    public function __construct(private readonly ReportShopperData $report, private readonly TenantContext $tenant) {}

    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind === 'report') {
            $this->report->handle($event);

            return;
        }

        if ($event->kind !== 'redact') {
            return;
        }

        $customer = preg_match('/^[0-9]{1,20}$/', $event->customerId) === 1 ? $event->customerId : null;
        $email = ShopperDataReport::emailHash($event->email, $event->shopId);

        if ($customer === null && $email === null) {
            return;
        }

        $this->tenant->run($event->shopId, fn () => ShopperDataReport::query()
            ->where(fn ($q) => $q->when($customer !== null, fn ($q) => $q->orWhere('customer_ref', $customer))->when($email !== null, fn ($q) => $q->orWhere('email_hash', $email)))
            ->delete());
    }
}
