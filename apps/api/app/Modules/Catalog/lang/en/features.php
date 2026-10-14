<?php

return [
    'daily_sync' => [
        'label' => 'Nightly catalog sync',
        'description' => 'Reads the whole catalog from the store every night at 02:30, Israel time.',
    ],
    'live_sync' => [
        'label' => 'New products and changes within minutes',
        'description' => 'Every 10 minutes asks the store what changed since the last look: a new or edited product enters the catalog and the search, one taken off the store leaves. Shopify only for now; WordPress by the nightly sync.',
    ],
];
