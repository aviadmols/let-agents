<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The Shopify app: its keys from the Partner dashboard. Empty: Shopify stores cannot install.
    'shopify' => [
        'key' => env('SHOPIFY_API_KEY'),
        'secret' => env('SHOPIFY_API_SECRET'),
        'version' => env('SHOPIFY_API_VERSION', '2026-07'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_content,read_online_store_pages,write_draft_orders'),
        'handle' => env('SHOPIFY_APP_HANDLE', 'let-agents'),
        // Custom-distribution apps for single stores, "client_id:secret" pairs separated by commas.
        'more_apps' => env('SHOPIFY_MORE_APPS', ''),
    ],
];
