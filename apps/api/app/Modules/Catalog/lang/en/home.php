<?php

return [
    'card' => [
        'label' => 'Pages and posts read',
        'note' => ':posts {posts:post/posts} · :pages {pages:page/pages}',
        'last' => 'Read :when',
    ],
    // The storefront is closed to visitors (StorefrontLock): a calm word that the scan read around it.
    'locked' => [
        'title' => 'Your store is closed to visitors',
        'source' => 'Store scan',
        'body' => [
            'password' => 'Your store is password-protected, so we read your products and pages from :platform instead of your storefront. Some details (like how your product pages look) will be read once the store is open.',
            'maintenance' => 'Your store is in maintenance mode, so we read your products and pages from :platform instead of your storefront. Some details (like how your product pages look) will be read once the store is open.',
            'coming_soon' => 'Your store shows a "coming soon" page, so we read your products and pages from :platform instead of your storefront. Some details (like how your product pages look) will be read once the store is open.',
            'age_gate' => 'Your store asks visitors their age before it opens, so we read your products and pages from :platform instead of your storefront. Some details (like how your product pages look) will be read once the store is open.',
        ],
    ],
];
