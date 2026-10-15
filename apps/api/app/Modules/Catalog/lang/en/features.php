<?php

return [
    'daily_sync' => [
        'label' => 'Nightly catalog sync',
        'description' => 'Reads the whole catalog from the store every night at 02:30, Israel time.',
    ],
    'live_sync' => [
        'label' => 'New products and changes within minutes',
        'description' => 'Every 10 minutes asks the store what changed since the last look: a new or edited product enters the catalog and the search, one taken off the store leaves. Shopify and WordPress.',
    ],
    'refresh' => [
        'label' => 'Prices and stock refreshed 3 times a day',
        'description' => 'At 08:30, 14:30 and 20:30 reads the products again, and only them (price, stock, what left the store), without categories, pages or posts. Also catches stock that changed without the product being marked as updated, for example from an inventory system. The search is rebuilt only when something really changed.',
    ],
];
