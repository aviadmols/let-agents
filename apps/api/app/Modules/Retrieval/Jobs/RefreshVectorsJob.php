<?php

namespace App\Modules\Retrieval\Jobs;

use App\Core\Facades\Features;
use App\Modules\Retrieval\Actions\BuildIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A shop's meaning vectors, brought up to date after its catalogue changed, without waiting for the
 * night. One at a time a shop, a few minutes after the change, so a burst of edits is one run; the
 * index reads everything again in code and embeds only the pieces whose text changed (BuildIndex),
 * each batch asking SpendGuard first. The nightly build stays: what this one missed, it finishes.
 */
final class RefreshVectorsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Changes within this many seconds of the first wait for the same run. */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $shopId) {}

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(BuildIndex $index): void
    {
        if (Features::enabled('retrieval.index', $this->shopId) && Features::enabled('retrieval.refresh_on_change', $this->shopId)) {
            $index->handle($this->shopId);
        }
    }
}
