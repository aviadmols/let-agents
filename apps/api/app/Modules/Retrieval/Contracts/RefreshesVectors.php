<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * A shop's meaning vectors brought up to date soon, for another module whose news changes what they
 * say (approved facts, a short description): one refresh a few minutes later, however often asked.
 */
interface RefreshesVectors
{
    public function soon(string $shopId): void;
}
