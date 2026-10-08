<?php

return [
    'index' => [
        'label' => 'Index by meaning',
        'description' => 'Every night, products, pages, posts and what the orders say are cut into pieces and given a vector, so they can be found by meaning. Only a piece whose text changed is sent to the model again.',
    ],
    'ai_matching' => [
        'label' => 'Matching with a model',
        'description' => 'Code finds candidates for each product (bought together, similar in meaning, named together in guides), a model chooses complements and alternatives from them, and code checks every choice. A choice that passes becomes a relation between products.',
    ],
    'image_index' => [
        'label' => 'Vectors for product pictures',
        'description' => 'Every night each product\\x27s main picture gets a vector, to find products that look alike and search pictures by words. Suits sites where the picture is the product, like fashion. A picture is embedded again only when its address changes.',
    ],
    'image_captions' => [
        'label' => 'Content descriptions for pictures',
        'description' => 'After the visual scan, a model that sees pictures writes what each shows, and the description becomes a content vector. Photo search combines both, and the words join the text search.',
    ],
];
