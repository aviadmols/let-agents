<?php

namespace App\Modules\Leads\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Contracts\ReadsContacts;

/**
 * A Shopify customer asked the store to erase what is kept about them: every lead they left with that email or
 * phone goes, found by the contact's code (the same code a lead keeps, salted by the shop).
 */
final class ForgetShopperLeads
{
    public function __construct(private readonly ReadsContacts $contacts) {}

    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind !== 'redact') {
            return;
        }

        $hashes = array_values(array_filter(array_map(fn (string $raw): ?string => $raw === '' ? null : ($this->contacts->read($raw, $event->shopId)['hash'] ?? null), [$event->email, $event->phone])));

        if ($hashes !== []) {
            app(TenantContext::class)->run($event->shopId, fn () => Lead::query()->whereIn('contact_hash', $hashes)->delete());
        }
    }
}
