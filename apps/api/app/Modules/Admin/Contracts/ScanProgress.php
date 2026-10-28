<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;

/**
 * How far a step of the store's scan has got, while it runs: so many of so many (products read, pictures looked at,
 * posts read, pages indexed), counted in code from what is already stored, never by asking a model. A module tags its
 * class 'admin.scan_progress' and answers for the steps it does, inside the shop. "total" is what the step will go
 * through in this scan, known before it starts where it can be (the time left is estimated from it); null when only
 * the count so far is known (a catalog still being read).
 */
interface ScanProgress
{
    public const TAG = 'admin.scan_progress';

    /**
     * @param  array<string, Carbon>  $began  step => when it began in this scan (a step not begun is not in it)
     * @return array<string, array{done: int, total: int|null}> step => how far
     */
    public function scanProgress(Shop $shop, array $began): array;
}
