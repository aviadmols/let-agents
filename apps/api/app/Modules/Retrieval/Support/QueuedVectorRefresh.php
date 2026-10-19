<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Retrieval\Contracts\RefreshesVectors;
use App\Modules\Retrieval\Jobs\RefreshVectorsJob;

/** One refresh of a shop's vectors, a few minutes later, on the queue (unique per shop). */
final class QueuedVectorRefresh implements RefreshesVectors
{
    public function soon(string $shopId): void
    {
        if (Features::enabled('retrieval.index', $shopId) && Features::enabled('retrieval.refresh_on_change', $shopId)) {
            RefreshVectorsJob::dispatch($shopId)->delay(now()->addMinutes((int) Settings::get('retrieval.refresh_after_minutes', $shopId)));
        }
    }
}
