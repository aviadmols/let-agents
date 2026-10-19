<?php

// The shop's settings page of the heart and the wishlist, as the designer's panel words it (EditsShopSettings, x-lp::*).
return [
    'heart' => [
        'title' => 'The heart and the wishlist',
        'help' => 'A heart on every product. Shoppers save, come back, share. The list is Lets\', not the theme\'s, so it works in any store.',
        'on_product' => 'On product pages',
        'on_product_sub' => 'Beside the add-to-cart button. Goes in on its own.',
        'on_cards' => 'On category pages and product lists',
        'on_cards_sub' => 'A heart on every product card',
        'in_results' => 'In Lets search results',
        'place' => 'Where the heart sits on a card',
        'places' => ['corner' => 'Top corner', 'price' => 'Beside the price', 'cart' => 'Beside add to cart'],
        'click' => 'What a click does',
        'clicks' => ['open' => 'Saved and the list opens', 'quiet' => 'Saved quietly'],
        'phone' => 'Keeping it over time',
        'phone_sub' => 'A shopper who confirms a phone gets the list on any device',
        'phone_tip' => 'Without confirming, the list stays in the browser only. A shopper who confirms a phone (a code by SMS) gets it on any device and can share it.',
        'detect_label' => 'Automatic detection on category pages',
        'detect_text' => 'Paste a link to one category page. Lets finds how a product card looks in your theme, and from then on the heart appears on every category page.',
        'detect' => 'Detect',
        'detected' => 'We found :count product cards on the page',
        'detected_where' => [
            'corner' => 'The heart will appear in the top corner of every card, on every category page.',
            'price' => 'The heart will appear beside the price of every card, on every category page.',
            'cart' => 'The heart will appear beside the add-to-cart button of every card, on every category page.',
        ],
        'activate' => 'Looks good, turn it on',
        'not_this' => 'Not this card',
        'active' => 'Detection is on',
        'active_text' => 'The heart appears on its own on the detected product cards, on every category page.',
        'activated' => 'Turned on. The heart will appear on category pages within minutes',
        'cleared' => 'Detection removed',
        'errors' => [
            'empty' => 'Paste a link to a category page.',
            'other_site' => 'Only a page of the store\'s own site (:domain) can be read.',
            'unreachable' => 'We could not open the page. Check that the link opens in a browser.',
            'no_cards' => 'We found no product cards on this page. Try a category page with a few products.',
            'too_many' => 'Too many tries. Try again in a minute.',
        ],
        'reach_label' => 'How it reaches the store',
        'reach' => [
            'woocommerce' => 'The heart goes into the product page on its own through the Let Agents plugin. Anywhere else in the theme: :code, or a link to :hash that opens the list.',
            'shopify' => 'The heart goes into the product page on its own through the App embed. Anywhere else: theme editor → App blocks → "Lets wishlist heart", and drag it where you like.',
        ],
        'lists' => 'The shoppers\' lists',
    ],
];
