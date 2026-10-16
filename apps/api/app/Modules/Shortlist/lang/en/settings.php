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
