<?php

// The ⓘ beside a number's title on the shop's screens, keyed by the label it explains (Admin\Support\Tips).
return [
    'ai' => 'How much of the store\'s monthly allowance is used: shoppers\' questions, scanning new products and insight checks. When it runs out, the assistant answers only from saved answers until the next month.',
    'questions' => 'Every question a shopper wrote to the assistant, on a product page or in the search window. "Answered from the knowledge" means an answer was found in the site\'s pages or the knowledge centre.',
    'products' => 'A product is scanned once its text and picture were read and its tags and spec were built. "Waiting to be read" are products not read yet.',
    'pages' => 'A page or post is scanned once its text was read and what it says about the store joined the knowledge centre.',
    'visitors' => 'Different shoppers who visited the site in the period. A shopper who came back is counted once.',
    'adds' => 'A click on "add to cart" from the search, the assistant or the product-page widget. Adding from the regular product page is not counted.',
    'orders' => 'An order with at least one product added to the cart through Lets in that visit.',
    'revenue' => 'The total of the orders Lets helped, before shipping and VAT.',
    'opens' => 'How many times the search window or the widget was opened. One shopper can open it several times.',
    'searches' => 'Every search sent. Typing stopped halfway is not counted. "By photo" means a shopper uploaded or took a photo.',
    'asks' => 'Questions to the assistant. "Not about the store" are unrelated questions (weather, politics) the assistant politely declined.',
    'store_adds' => 'Adds to cart: from search (its results and the assistant), from the widget (on the product page), and from the page (the store\'s own button, after the shopper came from Lets).',
    'failed' => 'A search "with no results" found no product. "No click" means products showed and none was chosen. Both hint at a word the store calls differently, or a product that is missing.',
    'ips' => 'An ordinary address makes a few dozen requests a day. Above the line it is usually a bot or a crawler. Blocking stops search and the assistant for that address; the store itself keeps working.',
    'sessions' => 'One visit to the site, from arriving until the shopper left. "Returning" are shoppers for whom it is not their first visit.',
    'steps' => 'On average, how many actions each visit had: pages, searches, questions, saves and adds to cart.',
    'scan_products' => 'A product is scanned once its text and picture were read and its tags and spec were built.',
    'scan_images' => 'Pictures described in words, so photo and description searches find them. "Unreadable" means the picture did not load or was not a picture.',
    'scan_tags' => 'Tags built from the categories, attributes, title and picture. Shoppers filter by them and the search finds through them.',
    'scan_missing' => 'Products missing a detail shoppers filter by (size, thickness, colour). A product without it does not show when they filter by it.',
];
