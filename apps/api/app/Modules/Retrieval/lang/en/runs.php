<?php

return [
    'indexed' => 'Index: :documents documents, :chunks pieces, :embedded given a vector, :pending waiting.',
    'switched_embedder' => 'Search by meaning now uses :to (it used :from); the old vectors were deleted and every shop is embedded again.',
    'matched' => 'Matching: :asked products asked about, :accepted choices accepted and :rejected refused, :unchanged unchanged.',
    'matching_off' => 'Matching with a model is off for this shop.',
    'query_vectors' => 'Search vectors: :count new queries today.',
    'images_indexed' => 'Pictures: :products products with a picture, :embedded given a vector, :kept unchanged, :pending waiting.',
    'images_no_provider' => 'Pictures failed: no model is set for picture vectors, so none of :products products with a picture was scanned. :pending waiting.',
    'images_refused' => 'Pictures failed: the picture model refused (:reason). :embedded given a vector, :kept unchanged, :pending waiting.',
    'captions_busy' => 'The pictures of this shop are already being described; this one did not start.',
    'images_busy' => 'Another picture scan of this shop is already running; this one did not start.',
    'captioned' => 'Picture descriptions: :described described, :left left.',
    'captions_off' => 'Picture descriptions are off in this shop.',
    'captions_no_provider' => 'Picture descriptions: no provider set for descriptions or for text vectors.',
    'chunk_experiment' => 'Piece size experiment: :pieces pieces of up to :chars characters, in memory only, the index untouched.',
    'chunk_experiment_no_provider' => 'Piece size experiment: no provider set for text vectors.',
    'embedder_trial' => 'Embedding model trial: :model on :queries searches and :pieces pieces, in memory only, the index untouched.',
];
