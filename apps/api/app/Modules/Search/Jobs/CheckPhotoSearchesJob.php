<?php

namespace App\Modules\Search\Jobs;

use App\Modules\Search\Actions\CheckPhotoSearches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Checks a shop's marked photos against the reader as it is now, on the long queue. */
final class CheckPhotoSearchesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Where the page reads that a check was asked for and has not finished. */
    public const RUNNING = 'search:photo-check:';

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly string $shopId)
    {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(CheckPhotoSearches $check): void
    {
        try {
            $check->handle($this->shopId);
        } finally {
            Cache::forget(self::RUNNING.$this->shopId);
        }
    }
}
