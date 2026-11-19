<?php

namespace App\Modules\Enrichment\Support;

use RuntimeException;

/** A model's reply did not hold to the schema beside its prompt. The run that asked stops: the reply is not mended. */
final class ReplySchemaBroken extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly string $step, public readonly array $problems)
    {
        parent::__construct('Reply of '.$step.' broke its schema: '.implode('; ', $problems));
    }
}
