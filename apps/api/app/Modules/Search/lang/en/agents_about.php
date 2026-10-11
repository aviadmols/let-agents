<?php

return [
    'tagger' => 'Writes tags such as "materials for building a deck" for each page. Code keeps only tags the search fills, and a model of another family checks each one.',
    'resolver' => 'At night: matches products and guides from the nearest by meaning to a search that found nothing, and a model of another family checks.',
    'photo_reader' => 'When a shopper uploads a photo: looks at it, says what it shows, what sets the item apart (stone colour, shape, metal) and where it is in the photo, and chooses from the store\'s categories. Code keeps only real categories, cuts the item out and compares it with the products\' pictures. With the second ordering on, another model sees the item beside the leading products and orders them. The same photo is never asked twice.',
];
