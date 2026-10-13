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
    'wishlist_place' => [
        'label' => 'Wishlist heart in the site header (CSS selector)',
        'description' => 'The heart shows right after this element, such as the search or cart icon: .header__icon--search. Empty: no heart is added to the header.',
    ],
    'product_selector' => [
        'label' => 'Where on the product page (CSS selector)',
        'description' => 'The buttons show under the first element found, usually the add-to-cart form. Empty: not on the product page.',
    ],
    'requests_per_minute' => [
        'label' => 'Requests per minute from one shopper',
        'description' => 'A limit on heart and compare clicks from one address, per site.',
    ],
    'card_finder_provider' => [
        'label' => 'Provider of the model that finds product cards',
        'description' => 'Asked only when code did not find the cards itself.',
    ],
    'card_finder_model' => [
        'label' => 'The model that finds product cards',
        'description' => 'For example gpt-5.4-mini.',
    ],
    'card_finder_input_usd_per_million' => [
        'label' => 'Input price, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'card_finder_output_usd_per_million' => [
        'label' => 'Output price, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'card_finder_max_output_tokens' => [
        'label' => 'Length of the reply',
        'description' => 'Tokens at most.',
    ],
];
