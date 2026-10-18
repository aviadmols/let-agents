<?php

return [
    'locale' => [
        'label' => 'Language',
        'short' => [
            'he' => 'עב',
            'en' => 'EN',
        ],
        'switch_to' => 'Switch to :language',
        'names' => [
            'he' => 'עברית',
            'en' => 'English',
        ],
    ],
    'switch' => [
        'operator' => 'Operator panel',
        'merchant' => 'Merchant view',
    ],
    'account' => [
        'viewing' => 'Your store',
        'as_operator' => 'You are viewing :shop as an operator. Everything here belongs to that store alone.',
        'switch' => 'Switch store',
    ],
    'shop' => [
        'label' => 'Store',
        'every' => 'Every store',
    ],
    // Sidebar groups. Operator: overview, shops, content, discovery, shoppers, analytics, system.
    // Merchant: overview, analytics, search, on_page, shoppers, settings.
    'nav' => [
        'overview' => 'Overview',
        'shops' => 'Stores',
        'catalog' => 'Catalog & content',
        'scan' => 'Scan & product data',
        'discovery' => 'Search & discovery',
        'shoppers' => 'Shoppers',
        'analytics' => 'Analytics',
        'system' => 'System',
        'search' => 'Search',
        'on_page' => 'On-page module',
        'settings' => 'Settings',
    ],
    'view_as_shop' => 'View as the shop manager',
    'top' => [
        'live' => 'Live in the store',
        'preview' => 'In preview',
        'as_operator' => 'Viewing as operator · back',
        'operator' => 'System operator',
        'open_store' => 'Open the store',
        'theme' => 'Appearance',
        'logout' => 'Sign out',
        'themes' => [
            'light' => 'Light',
            'dark' => 'Dark',
            'system' => 'System',
        ],
        'brand' => 'Store panel',
    ],
    // The shop's screens, top to bottom (MerchantNavigation::SECTIONS).
    'screens' => [
        'analytics' => 'Analytics',
        'sessions' => 'Sessions',
        'timeline' => 'Timeline',
        'inbox' => 'Notices and insights',
        'knowledge' => 'Knowledge centre',
        'scan' => 'Scan summary',
        'settings' => 'Settings',
    ],
];
