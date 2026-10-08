<?php

namespace App\Modules\Retrieval\Contracts;

use App\Modules\Runs\Models\Run;

/** Writes what a shop's scanned pictures show, in words and as a text vector, a part at a time. */
interface CaptionsPictures
{
    /** One part; its output's "stopped" is "more" while pictures are left. */
    public function captions(string $shopId): Run;
}
