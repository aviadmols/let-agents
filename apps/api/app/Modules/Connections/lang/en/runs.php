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
    ],
];
