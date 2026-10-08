<?php

return [

    /*
    | Modules live in app/Modules/{Name}. Each one declares itself in module.json and the
    | kernel (app/Core) discovers, orders and registers it. See docs/ADR/0001.
    */
    'modules' => [
        'path' => app_path('Modules'),
        'namespace' => 'App\Modules',
    ],

    /*
    | Languages the admin panels and the plugin settings screen are available in.
    | The first one is the fallback when nothing else decides.
    */
    'locales' => ['he', 'en'],

    /*
    | Scripts written right-to-left. Anything not listed is left-to-right.
    */
    'rtl_locales' => ['he', 'ar', 'fa', 'ur'],

    /*
    | Where the downloadable store plugin zip lives. The Docker build puts it here; locally run
    | "php artisan connections:bundle-plugin".
    */
    'plugin' => [
        'path' => resource_path('plugins'),
    ],

    /*
     * Each shop's panel on its own address: {shop}.agents.lets.co.il. Empty keeps every shop on
     * one address, /merchant/{shop}. Needs a wildcard DNS record and custom domain on Railway,
     * and SESSION_DOMAIN=.agents.lets.co.il so one login serves every shop's address.
     */
    'shop_domain' => env('SHOP_DOMAIN'),
];
