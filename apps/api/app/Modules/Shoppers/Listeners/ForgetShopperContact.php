<?php

namespace App\Modules\Shoppers\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Support\Contact;

/**
 * A Shopify customer asked the store to erase what is kept about them: the identity they signed up with by that
 * email or phone goes, and with it (foreign keys) the browsers it was linked to and the callbacks it asked for.
 */
final class ForgetShopperContact
{
    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind !== 'redact') {
            return;
        }

        $hashes = array_values(array_filter(array_map(fn (string $raw): ?string => $raw === '' ? null : Contact::parse($raw, $event->shopId)?->hash($event->shopId), [$event->email, $event->phone])));

        if ($hashes !== []) {
            app(TenantContext::class)->run($event->shopId, fn () => ShopperIdentity::query()->whereIn('contact_hash', $hashes)->delete());
        }
    }
}
