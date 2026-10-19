<?php

return [
    'singular' => 'API key',
    'plural' => 'API keys',
    'fields' => [
        'name' => 'Name',
        'name_help' => 'For example "Main site plugin". Helps you recognise the key later.',
        'prefix' => 'Prefix',
        'last_used_at' => 'Last used',
        'never_used' => 'Never used',
        'status' => 'Status',
        'created_at' => 'Created',
    ],
    'statuses' => [
        'active' => 'Active',
        'revoked' => 'Revoked',
    ],
    'actions' => [
        'issue' => 'Issue key',
        'revoke' => 'Revoke key',
        'revoke_confirm' => 'The plugin using this key stops working immediately. This cannot be undone.',
    ],
    'issued' => [
        'title' => 'Key created. Copy it now',
        'body' => 'This is the only time the key is shown. Paste it into the plugin settings: :key',
        'only_now' => 'This is the only time the key is shown. Copy it and paste it in the plugin settings before closing this window.',
        'key' => 'The key',
        'copied' => 'Key copied',
        'close' => 'Copied, close',
    ],
    'errors' => [
        'limit_reached' => 'This shop already has :limit active keys, the maximum allowed. Revoke an old key or raise the cap in settings.',
    ],
];
