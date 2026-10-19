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
    'side_groups' => [
        'store' => 'The store',
        'shoppers' => 'What shoppers see',
        'team' => 'The team and replies',
        'connect' => 'Connection',
        'account' => 'The account',
    ],
    'close' => 'Close',
    // A short hint the first time each screen opens (merchant-section).
    'hints' => [
        'analytics' => 'Everything shoppers did with Lets. "Full list" opens the whole list, with search and sorting.',
        'sessions' => 'Each visit is a list of actions. Filter above, and click a session to see what the shopper did, in order.',
        'timeline' => 'Everything that happened in the account: scans, new products, answers and changes. Click a line for the details.',
        'inbox' => 'What waits for you. An answer you approve here joins the knowledge, and every next shopper gets it.',
        'knowledge' => 'What Lets knows about the store. Every line is a possible answer to a shopper. Something not right? Remove it, and it is not read again.',
        'scan' => 'How Lets understood each product and page. Pick one, and see its tags, spec and what the picture showed.',
        'settings' => 'Each setting on its own page. Pick from the menu, and shoppers see the change within minutes.',
    ],
];
