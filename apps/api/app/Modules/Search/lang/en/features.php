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
    'resolve_empty' => [
        'label' => 'Resolve searches that found nothing, with a model',
        'description' => 'At night, never live. A search that came back empty several times gets a vector and candidates from the catalogue; a model matches products and guides, and a model from another family checks. From the morning that search shows them. What was not found goes to the site suggestions.',
    ],
    'page_tags' => [
        'label' => 'Tag bank on pages',
        'description' => 'At night, for every product and guide: a model writes tags such as "materials for building a deck" or "deck screws", the search checks there is something to show, and a model from another family checks each tag against what it shows. Needed for the tag bank view of the on-page module.',
    ],
];
