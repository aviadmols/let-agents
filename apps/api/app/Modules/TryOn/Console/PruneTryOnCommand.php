<?php

namespace App\Modules\TryOn\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Files;
use Illuminate\Console\Command;

/**
 * Deletes the pictures of photos past their keeping date (the shop's retention days, set when the
 * photo came in): the photo, every picture made from it, and the pictures its steps kept. The rows
 * stay, without paths, so the log still says what happened and what it cost.
 */
final class PruneTryOnCommand extends Command
{
    protected $signature = 'try-on:prune';

    protected $description = 'Delete Try-On pictures past the shop\'s retention days.';

    public function handle(TenantContext $tenant): int
    {
        $deleted = $tenant->runUnscoped(function (): int {
            $count = 0;

            TryOnPhoto::query()->where('expires_at', '<', now())->whereNotNull('path')->orderBy('id')->each(function (TryOnPhoto $photo) use (&$count): void {
                foreach (TryOnStep::query()->where('photo_id', $photo->id)->whereNotNull('images')->get() as $step) {
                    foreach ((array) $step->images as $path) {
                        Files::delete($path);
                    }
                    $step->update(['images' => null]);
                }
                foreach (TryOnJob::query()->where('photo_id', $photo->id)->whereNotNull('result_path')->get() as $job) {
                    Files::delete($job->result_path);
                    $job->update(['result_path' => null]);
                }
                Files::delete($photo->path);
                $photo->update(['path' => null]);
                $count++;
            });

            return $count;
        });

        $this->info("Deleted the pictures of {$deleted} photos.");

        return self::SUCCESS;
    }
}
