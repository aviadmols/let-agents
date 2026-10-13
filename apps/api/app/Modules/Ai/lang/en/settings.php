<?php

return [
    'monthly_spend_cap_usd' => [
        'label' => 'Monthly model spending cap',
        'description' => 'A model call with the keys in the panel is blocked before it is sent when this month\'s cost plus its estimate would pass this amount. Work done outside the platform and uploaded as answer files does not count.',
    ],
    'shop_monthly_cap_usd' => [
        'label' => 'Monthly allowance per shop',
        'description' => 'How many dollars of model calls one shop may spend in a calendar month. The shop manager sees only a share used, never amounts. Only an operator changes it.',
    ],
    'shop_cap_stops_shoppers' => [
        'label' => 'At the end of the allowance, stop what shoppers ask too',
        'description' => 'Off: when the allowance runs out only background work stops (night scans, suggestions, reports) and the site keeps answering shoppers. On: questions, photo search and search vectors stop too, and the site answers only from what is already saved.',
    ],
    'shop_cap_warn_percent' => [
        'label' => 'Warning before the allowance ends',
        'description' => 'From what share used the top bar is marked as near the end, for the operator and the shop manager.',
    ],
];
