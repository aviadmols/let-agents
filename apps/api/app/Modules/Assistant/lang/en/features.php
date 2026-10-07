<?php

return [
    'on_products' => [
        'label' => 'Free questions on product pages',
        'description' => 'A shopper asks about the product and gets an answer from its information only. Questions answered before come back from memory without a model.',
    ],
    'on_content' => [
        'label' => 'Free questions on article pages',
        'description' => 'A reader asks about the guide and is answered from the guide itself, including "sum this up for me". Questions already answered come back from memory with no model.',
    ],
    'on_search' => [
        'label' => 'Questions in the search box',
        'description' => 'A shopper who types a question in the search and presses Enter gets an answer from the site pages, with the pages it came from. A saved answer shows while typing. No model is asked while typing.',
    ],
];
