<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * What a step of the store's scan learned, opened by a click on the step: the product types read,
 * the attributes and tags learned for each, how many products the search holds, new products by
 * day. A module tags its class 'admin.scan_details' and answers for the steps it knows, inside the
 * shop, in the shop manager's words: never a model, a cost or a mechanism.
 */
interface ScanDetails
{
    public const TAG = 'admin.scan_details';

    /**
     * @return array<string, list<array{title: string, rows: list<array{0: string, 1: string}>}>> step => sections
     */
    public function scanDetails(Shop $shop): array;
}
