<?php

namespace App\Modules\Search\Jobs;

use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\SettleWordFamilies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** The store's word families and searched words settled now, from the families screen, in the background. */
final class SettleWordFamiliesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $shopId) {}

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(SettleWordFamilies $settle): void
    {
        $settle->handle($this->shopId, RunTrigger::Manual, force: true);
    }
}
