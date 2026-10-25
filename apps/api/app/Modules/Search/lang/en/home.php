<?php

return [
    'open' => 'Open',
    'search' => [
        'name' => 'Site search',
        'on' => ':products {products:product/products} in the search · :count {count:search/searches} on the site in the last 7 days',
        'setup' => ':products products in the search, but no searches were seen on the site this week. It is probably not connected to the theme\'s search box yet.',
        'off' => 'Off: the theme\'s own search works on the site',
        'connect' => 'How to connect',
    ],
    'photos' => [
        'name' => 'Search by photo',
        'on' => 'Shoppers can take or upload a photo and find similar products',
        'off' => 'Off: it can be turned on on the search display screen',
    ],
    'empty' => [
        'title' => '":query": :searches {searches:search/searches}, :empty with no results',
        'body' => 'Shoppers look for this and find nothing. A synonym or a matching product would solve it.',
        'action' => 'To site searches',
    ],
    'step' => [
        'on' => [
            'title' => 'Turn Lets on in the store',
            'body_woocommerce' => 'In WordPress: Let Agents plugin settings → Mode → Live. Until then only you see the new search.',
            'body_shopify' => 'The button opens your Shopify theme editor with Lets switched on: just press Save. Until then only you see the new search.',
            'button_shopify' => 'Turn on in your theme',
            'button' => 'How to turn it on',
        ],
        'look' => [
            'title' => 'See it as a shopper',
            'body' => 'The real search, on your products, in the store itself. Only you see it until you turn it on.',
            'button' => 'Open',
        ],
    ],
];
