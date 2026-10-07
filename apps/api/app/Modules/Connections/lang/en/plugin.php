<?php

return [
    'title' => 'Store plugin',
    'subheading' => 'The WooCommerce plugin that connects a store to Let Agents. The file is built from the same version of the code running here.',
    'download' => 'Download plugin',
    'download_version' => 'Download plugin :version',
    'missing' => 'The plugin file is not on the server. Deploys build it automatically. In development run: php artisan connections:bundle-plugin',
    'sections' => [
        'file' => 'File',
        'install' => 'Installing in a store',
        'access' => 'What the plugin shares',
    ],
    'fields' => [
        'file' => 'File name',
        'version' => 'Version',
        'size' => 'Size',
        'kilobytes' => ':size KB',
    ],
    'steps' => [
        'upload' => 'In WordPress admin: Plugins > Add New > Upload Plugin. Choose the file, install and activate.',
        'token' => 'WooCommerce > Let Agents > Create token.',
        'copy' => 'Copy the token, which starts with lat_. It is shown only once.',
        'connect' => 'Here: Store connections > choose the shop > paste the site address and the token. The connection is tested at once.',
    ],
    'access' => [
        'reads' => 'Read-only access to products, variations, categories, attributes, custom fields, and content the store owner allowed.',
        'never' => 'No write access of any kind, and no access to customers, orders or users.',
        'revoke' => 'Revoking the token in the plugin cuts access immediately.',
    ],
];
