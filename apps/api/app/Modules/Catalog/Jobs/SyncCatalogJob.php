<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Actions\SyncCatalog;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a catalog sync on the worker. A large store takes minutes, which a web request must not
 * wait for. One sync per shop at a time; the run shows up live in the agent activity log.
 */
final class SyncCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Seconds. The queue's retry_after must stay above this. */
    public int $timeout = 1200;

    /** A failed sync is recorded as a failed run; retrying blindly would only hammer the store. */
    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $shopId,
        public readonly RunTrigger $trigger = RunTrigger::Manual,
    ) {
        // Minutes of work: on the long queue, so quick work never waits behind it.
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(SyncCatalog $sync): void
    {
        $sync->handle($this->shopId, $this->trigger);
    }
}
