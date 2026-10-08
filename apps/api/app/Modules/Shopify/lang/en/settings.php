<?php

return [
    'plan_name' => [
        'label' => 'Plan name in Shopify',
        'description' => 'As the merchant sees it on the Shopify invoice.',
    ],
    'plan_price_usd' => [
        'label' => 'Price per month (USD)',
        'description' => 'Billed through Shopify every 30 days. A change applies to new subscriptions.',
    ],
    'trial_days' => [
        'label' => 'Trial days',
        'description' => '0: billed from the first day.',
    ],
    'billing_test' => [
        'label' => 'Test billing for every store',
        'description' => 'Development stores are always billed in test mode. This puts real stores in test mode too: before launch only.',
    ],
];
