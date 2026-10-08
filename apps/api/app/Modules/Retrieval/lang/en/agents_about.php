<?php

return [
    'indexer' => 'Turns every product, article and page into a text vector, so search by meaning finds them. Only what changed since the night before.',
    'image_indexer' => 'Turns the main picture of every product into a vector, for search by photo and look-alike products. A picture is embedded once, and again only when it changes.',
    'query_embedder' => 'One vector per new wording a shopper searches, kept for the next time. The same model as the index, so the two can be compared.',
    'matcher' => 'Decides which products go together and which replace each other, from candidates code found.',
    'image_captioner' => 'After the visual scan: writes a sentence and words for what each picture shows, and they become a content vector. A picture is described again only when it changes.',
];
