<?php

// What each agent does, for the agents screen. Plain words.
return [
    'tagger' => 'Writes tags such as "materials for building a deck" for each page. Code keeps only tags the search fills, and a model of another family checks each one.',
    'resolver' => 'At night: matches products and guides from the nearest by meaning to a search that found nothing, and a model of another family checks.',
    'photo_reader' => 'When a shopper uploads a photo: looks at it and picks, from the shop\'s categories, what it shows (pine wood, a pergola). Code keeps only real categories and words that find products in the shop. The same photo is never asked twice.',
];
