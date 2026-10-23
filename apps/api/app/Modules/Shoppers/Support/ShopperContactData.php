<?php

namespace App\Modules\Shoppers\Support;

use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;

/**
 * A shopper asked the store for what it keeps about them: the contact they signed up with by that email or phone
 * (found by its code, as ForgetShopperContact finds it to erase), how many browsers it was linked to, and the
 * questions they left to be called back about. Asked inside the shop.
 */
final class ShopperContactData implements KeepsShopperData
{
    public function about(ShopperDataRequested $request): array
    {
        $hashes = array_values(array_filter(array_map(fn (string $raw): ?string => $raw === '' ? null : Contact::parse($raw, $request->shopId)?->hash($request->shopId), [$request->email, $request->phone])));

        if ($hashes === []) {
            return [];
        }

        $identities = ShopperIdentity::query()->whereIn('contact_hash', $hashes)->withCount('visitors')->orderBy('id')->get();

        if ($identities->isEmpty()) {
            return [];
        }

        $callbacks = ShopperCallback::query()->whereIn('identity_id', $identities->modelKeys())->orderBy('created_at')->orderBy('id')->get()->map(fn (ShopperCallback $c): array => [
            'asked_at' => $c->created_at?->toIso8601String(),
            'name' => $c->name,
            'question' => $c->question,
            'page' => $c->page_type.' '.$c->page_id,
            'answered_at' => $c->answered_at?->toIso8601String(),
        ])->all();

        return array_filter([
            'contact_details' => $identities->map(fn (ShopperIdentity $identity): array => [
                'channel' => $identity->channel,
                'contact' => $identity->contact,
                'signed_up_at' => $identity->created_at?->toIso8601String(),
                'confirmed_at' => $identity->verified_at?->toIso8601String(),
                'agreed_at' => $identity->consented_at?->toIso8601String(),
                'agreed_to_version' => $identity->consent_version,
                'last_seen_at' => $identity->last_seen_at?->toIso8601String(),
                'browsers_linked' => (int) $identity->visitors_count,
            ])->all(),
            'callback_requests' => $callbacks,
        ]);
    }
}
