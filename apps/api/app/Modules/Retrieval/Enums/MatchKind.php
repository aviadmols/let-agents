<?php

namespace App\Modules\Retrieval\Enums;

/** What the model may say about a candidate. Family (other sizes) stays a job for code. */
enum MatchKind: string
{
    /** Bought with the product: an accessory, a consumable, the next step of the same job. */
    case Complement = 'complement';

    /** Bought instead of the product: the same job, another brand, size or price. */
    case Alternative = 'alternative';
}
