<?php

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Models\Lead;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Contracts\ReadsContacts;

/**
 * A shopper asked the store for what it keeps about them: every lead they left by that email or phone (found by
 * the contact's code, as ForgetShopperLeads finds them to erase), with what they wrote and agreed to. Asked inside
 * the shop.
 */
final class LeadShopperData implements KeepsShopperData
{
    public function __construct(private readonly ReadsContacts $contacts) {}

    public function about(ShopperDataRequested $request): array
    {
        $hashes = array_values(array_filter(array_map(fn (string $raw): ?string => $raw === '' ? null : ($this->contacts->read($raw, $request->shopId)['hash'] ?? null), [$request->email, $request->phone])));

        if ($hashes === []) {
            return [];
        }

        $leads = Lead::query()->whereIn('contact_hash', $hashes)->orderBy('created_at')->orderBy('id')->get()->map(fn (Lead $lead): array => [
            'left_at' => $lead->created_at?->toIso8601String(),
            'channel' => $lead->channel,
            'contact' => $lead->contact,
            'answers' => (array) ($lead->answers ?? []),
            'page' => $lead->page_type.' '.$lead->page_external_id,
            'agreed_at' => $lead->consented_at?->toIso8601String(),
            'agreed_to' => $lead->consent_wording,
            'status' => $lead->status,
        ])->all();

        return $leads === [] ? [] : ['leads' => $leads];
    }
}
