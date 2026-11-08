<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * "Fixed by Lets" on the Improvements screen (the redesign, 11.2026): what was clear enough to fix without the owner
 * (a typo, an obvious word of the same meaning), each with what shoppers found since, and the one tap that undoes it.
 * A module tags its class 'admin.auto_fixes'. Plain arrays only.
 */
interface AutoFixes
{
    public const TAG = 'admin.auto_fixes';

    /**
     * @return list<array{key: string, from: string, to: string, kind: string, at: string, found: int, picture: string|null}> the latest first; kind: typo or same
     */
    public function fixed(Shop $shop, int $limit): array;

    /** How many there are in all. */
    public function count(Shop $shop): int;

    /** Undo one, as the owner. Null when the key is not this source's. */
    public function undo(Shop $shop, string $key, ?int $userId): ?string;
}
