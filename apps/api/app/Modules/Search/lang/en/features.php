<?php

return [
    'storefront' => [
        'label' => 'Let Agents search in the store\'s search box',
        'description' => 'Typo-tolerant suggestions while typing, and results grouped into products, guides and categories. The store also turns it on in the plugin.',
    ],
    'semantic' => [
        'label' => 'Search by meaning',
        'description' => 'Also finds what is not called by the typed words, like "something to join boards". Each new wording costs one small vector; a repeated one costs nothing.',
    ],
    'pictures' => [
        'label' => 'Search in pictures',
        'description' => 'Words like "striped shirt" also find products whose names do not say so, by their picture. Works only once the shop\x27s pictures have vectors.',
    ],
    'photos' => [
        'label' => 'Search by photo',
        'description' => 'A camera button in the search box: a shopper uploads or takes a photo, and the store shows products that look alike. Shows only once the shop\'s pictures have vectors. Each photo costs one vector, and the photo is not kept.',
    ],
];
