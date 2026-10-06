<?php

namespace App\Modules\Retrieval\Contracts;

use App\Modules\Runs\Models\Run;

/** Building a shop's index and matching its products, for screens and other modules. */
interface RunsRetrieval
{
    public function index(string $shopId): Run;

    /** @param list<string>|null $productIds only these, asked again even if nothing changed */
    public function match(string $shopId, ?array $productIds = null): Run;
}
