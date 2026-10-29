<?php

return [
    'tagger' => 'Writes tags such as "materials for building a deck" for each page. Code keeps only tags the search fills, and a model of another family checks each one.',
    'resolver' => 'At night: matches products and guides from the nearest by meaning to a search that found nothing, and a model of another family checks.',
    'photo_reader' => 'When a shopper uploads a photo: looks at it, says what it shows, what sets the item apart (stone colour, shape, metal) and where it is in the photo, and chooses from the store\'s categories. Code keeps only real categories, cuts the item out and compares it with the products\' pictures. With the second ordering on, another model sees the item beside the leading products and orders them. The same photo is never asked twice.',
    'family_writer' => 'After the index is built: groups the store\'s words into families a shopper means as one (forms of a word, synonyms, near genres). A model of the other family checks. Only when the store\'s words change.',
    'family_judge' => 'After the families are written, each night: a strong model of the other family judges each waiting family (all the same thing, part of it, or none) and each word searched with no family (of a family, of a store word, or of none). Code keeps only what it gave, saved as the judge\'s decision. The team\'s families are never judged.',
    'word_reader' => 'A word a shopper typed that the store does not write ("striped") is matched to one it does ("stripes"), among the store\x27s own words only. Once per word, kept.',
    'scan_checker' => 'At the end of a scan: searches the store as shoppers will, on searches made from its products and on searches a small model writes (a need, a use, a feature), and mends for this store alone what fails: tags from the products\' descriptions, and searches one model matches and a model of the other family checks. Sends a report.',
];
