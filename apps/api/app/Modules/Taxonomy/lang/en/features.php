<?php

return [
    'tagging' => [
        'label' => 'Tag bank and product tagging',
        'description' => 'Builds a tag bank for the store from what it sells (genre, occasion, material) and tags every product and post, with the evidence and where it was read from. In the pilot it runs only on request.',
    ],
    'in_search' => [
        'label' => 'Tags in search',
        'description' => 'Tags help the search find and order products (by words and by meaning). A product without a tag is never hidden. Off until the pilot\'s results are checked.',
    ],
    'in_filters' => [
        'label' => 'Tags as filters',
        'description' => 'A group of tags marked as a filter is shown beside the results, as a category is. Only a tag the checker held, central to the product and read from the store\'s text or picture. Off until the pilot\'s results are checked.',
    ],
];
