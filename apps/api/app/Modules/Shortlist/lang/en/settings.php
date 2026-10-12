<?php

return [
    'compare_max' => [
        'label' => 'Products in one comparison',
        'description' => 'The most products in one comparison table.',
    ],
    'wishlist_max' => [
        'label' => 'Products in a wishlist',
        'description' => 'A new product goes to the top of the list; when it is full, the oldest leaves.',
    ],
    'card_selector' => [
        'label' => 'Product card on category pages (CSS selector)',
        'description' => 'The selector of a product card in the site\'s theme, such as li.product or .card-wrapper. Empty: the buttons show only in the search results and on the product page.',
    ],
    'product_selector' => [
        'label' => 'Where on the product page (CSS selector)',
        'description' => 'The buttons show under the first element found, usually the add-to-cart form. Empty: not on the product page.',
    ],
    'requests_per_minute' => [
        'label' => 'Requests per minute from one shopper',
        'description' => 'A limit on heart and compare clicks from one address, per site.',
    ],
];
