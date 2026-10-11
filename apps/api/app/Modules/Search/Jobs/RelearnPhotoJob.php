<?php

namespace App\Modules\Search\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\ReadPhotoTags;
use App\Modules\Search\Models\SearchPhotoAsk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A photo the team marked wrong, read again by the stronger model with their word, on the queue. */
final class RelearnPhotoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public readonly string $shopId, public readonly string $askId)
    {
        $this->onQueue('long');
    }

    public function handle(ReadPhotoTags $reader, TenantContext $tenant): void
    {
        $tenant->run($this->shopId, function () use ($reader): void {
            $ask = SearchPhotoAsk::query()->find($this->askId);
            $bytes = $ask?->photoBytes();

            if ($ask === null || $bytes === null || $ask->verdict !== SearchPhotoAsk::WRONG) {
                return;
            }

            $lesson = $reader->relearn($this->shopId, $bytes, $ask->object, $ask->expected);

            if ($lesson !== null) {
                $ask->forceFill(['checked_main' => $lesson->main_title ?? $lesson->object, 'checked_right' => null, 'checked_at' => now()])->save();
            }
        });
    }
}
