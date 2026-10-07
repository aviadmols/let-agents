<?php

return [
    'singular' => 'Shop',
    'plural' => 'Shops',
    'fields' => [
        'name' => 'Shop name',
        'slug' => 'URL identifier',
        'slug_help' => 'Leave empty to build one from the name.',
        'platform' => 'Platform',
        'domain' => 'Domain',
        'domain_help' => 'For example store.com. www and any path are removed automatically.',
        'content_locale' => 'Visitor content language',
        'currency' => 'Currency',
        'timezone' => 'Time zone',
        'status' => 'Status',
        'active_keys' => 'Active keys',
        'created_at' => 'Created',
    ],
    'sections' => [
        'identity' => 'Shop details',
        'locale' => 'Language and region',
    ],
    'platforms' => [
        'woocommerce' => 'WooCommerce',
        'shopify' => 'Shopify',
    ],
    'statuses' => [
        'active' => 'Active',
        'paused' => 'Paused',
        'disabled' => 'Disabled',
    ],
    'locales' => [
        'he' => 'Hebrew',
        'en' => 'English',
    ],
    'actions' => [
        'configure' => 'Settings and flags',
        'merchant_view' => 'Enter as the shop manager',
    ],
];
