<?php

namespace App\Modules\Retrieval\Enums;

/**
 * The sources this module indexes itself. Another module can add a source of its own (a
 * DocumentSource tagged retrieval.sources) under any key; these are only the built-in ones.
 */
enum ChunkSource: string
{
    case Product = 'product';

    /** Pages and posts the store shares. */
    case Content = 'content';

    /** What a product's orders say: how often it sells and what with. One per product sold. */
    case Purchases = 'purchases';
}
