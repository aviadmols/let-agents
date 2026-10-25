<?php

namespace App\Modules\Leads\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Contracts\DescribesLeadForm;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\LeadFields;

/**
 * The shop's "leave details" form as the storefront draws it: the flow in force's fields, in its order,
 * each with its required flag, under the shop's heading, with its consent and its promise. Code only.
 */
final class DescribeLeadForm implements DescribesLeadForm
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function forShop(string $shopId, string $locale): ?array
    {
        if (! Features::enabled('shoppers.callbacks', $shopId)) {
            return null;
        }

        $flow = $this->tenant->run($shopId, fn (): ?LeadFlow => LeadFlow::inForce($shopId));

        if ($flow === null || ! $flow->canReachAnyone()) {
            return null;
        }

        $locale = LeadFields::locale($locale);

        return [
            'fields' => array_map(fn (array $field): array => [
                'key' => (string) $field['key'],
                'type' => (string) $field['type'],
                'label' => (string) (LeadFields::said($field, $locale)['label'] ?? $field['key']),
                'required' => (bool) ($field['required'] ?? false),
            ], $flow->asked()),
            'title' => LeadFields::title($shopId, $locale),
            'promise' => LeadFields::promise($locale, $flow->promise),
            'consent' => LeadFields::consent((string) $flow->consent, $locale),
        ];
    }
}
