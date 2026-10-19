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
    'wishlist_whatsapp' => [
        'label' => 'Sharing the list on WhatsApp',
        'description' => 'A "Send on WhatsApp" button beside "Copy link". Off: the link can only be copied.',
    ],
    'heart_on_product' => [
        'label' => 'A save button on the product page',
        'description' => 'A "Save" heart beside the add-to-cart button. On a save the picture flies to the search icon, the icon turns into a heart for a moment, and a dot stays on it. No number: the cart stays the only counter in the header.',
    ],
    'heart_on_cards' => [
        'label' => 'A heart on product cards',
        'description' => 'On category pages and product lists, on every card. The same behaviour as on the product page.',
    ],
    'heart_place' => [
        'label' => 'Where the heart sits on a card',
        'description' => 'In the top corner of the picture, beside the price, or beside the add-to-cart button.',
        'options' => ['corner' => 'Corner of the picture', 'price' => 'Beside the price', 'cart' => 'Beside add to cart'],
    ],
    'heart_in_results' => [
        'label' => 'Heart in Lets search results',
        'description' => 'A heart on every product in the search window. Off: saving only from the store pages.',
    ],
    'heart_click' => [
        'label' => 'What a heart click does',
        'description' => 'Saved quietly: the picture flies to the search icon and a dot stays on it. Saved and the list opens: the list opens right away.',
        'options' => ['quiet' => 'Saved quietly', 'open' => 'Saved and the list opens'],
    ],
    'card_selector' => [
        'label' => 'Product card on category pages',
        'description' => 'The CSS selector of a product card in the theme, found on one of the store category pages. Every such card gets a heart.',
    ],
    'requests_per_minute' => [
        'label' => 'Requests per minute from one shopper',
        'description' => 'A limit on heart and compare clicks from one address, per site.',
    ],
    'phone_codes_per_hour' => [
        'label' => 'Codes per number an hour',
        'description' => 'How many SMS codes one number may ask for in an hour.',
    ],
    'phone_codes_per_ip_hour' => [
        'label' => 'Codes per address an hour',
        'description' => 'How many codes one network address may ask for in an hour, all numbers together.',
    ],
    'phone_codes_per_day' => [
        'label' => 'Codes per shop a day',
        'description' => 'The shop\'s cap on SMS messages a day. 0 sends none.',
    ],
    'visit_keep_days' => [
        'label' => 'Days an IP and a session are kept',
        'description' => 'Then the IP address and the session of wishlists are deleted. The lists stay.',
    ],
];
