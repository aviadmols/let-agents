<?php

namespace App\Modules\Admin\Contracts;

/**
 * A page or post the team asked to have read again (the scan summary's "scan again"): what the
 * store's pages teach the assistant is read for it first, now, whatever its age. One module answers it.
 */
interface ReadsContentAgain
{
    public function readAgain(string $shopId, string $contentId): void;
}
