<?php

return [
    'connected' => 'Connected. Plugin version :version, :products published products.',
    'failures' => [
        'invalid_token' => 'The token was rejected (HTTP :status). Create a new token in WooCommerce > Let Agents and paste it here.',
        'locked_out' => 'The site is temporarily blocking after failed attempts (HTTP :status). Try again in ten minutes.',
        'plugin_missing' => 'No Let Agents plugin found on the site (HTTP :status). Check that the plugin is installed and active and the address is right.',
        'woocommerce_inactive' => 'The plugin is installed but WooCommerce is not active on the site.',
        'http_error' => 'The site returned an error (HTTP :status).',
        'unexpected_response' => 'The site answered, but not like the Let Agents plugin (HTTP :status). A security or cache plugin may be changing the response.',
        'unreachable' => 'Cannot connect to the site. Check the address and that the site is up.',
        'unsupported_platform' => 'There is no way yet to read a store on this platform.',
        'not_installed' => 'The app is not installed in the store, or its access expired. It needs to be installed again.',
        'plugin_outdated' => 'The plugin on the site is too old to take posts. Update the Let Agents plugin to 0.8.0 or later.',
        'missing_scope' => 'The Shopify app may not write to the blog yet. Open the app in the Shopify admin and approve the new permission.',
        'rejected' => 'The store did not take the post.',
    ],
];
