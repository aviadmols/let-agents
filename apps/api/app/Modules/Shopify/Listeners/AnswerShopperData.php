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
 *
 * What it works with is taken when it runs, never in its constructor: under Octane the event dispatcher builds a
 * listener from the app as it booted, whose TenantContext is not the request's one the tenant scope reads, so the shop
 * it ran with was never seen (11.11: a customers/redact failed eight times and was dropped, and the listeners after
 * it, which forget the shopper's leads, contact and saved list, never ran).
 */
final class AnswerShopperData
{
    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind === 'report') {
            app(ReportShopperData::class)->handle($event);

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

        app(TenantContext::class)->run($event->shopId, fn () => ShopperDataReport::query()
            ->where(fn ($q) => $q->when($customer !== null, fn ($q) => $q->orWhere('customer_ref', $customer))->when($email !== null, fn ($q) => $q->orWhere('email_hash', $email)))
            ->delete());
    }
}
