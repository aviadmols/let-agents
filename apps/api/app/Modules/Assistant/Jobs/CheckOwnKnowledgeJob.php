<?php

namespace App\Modules\Assistant\Jobs;

use App\Modules\Assistant\Actions\CheckOwnKnowledge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A line the team added on the knowledge screen, checked against the site in the background. */
final class CheckOwnKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $shopId, public readonly string $rowId) {}

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->rowId;
    }

    public function handle(CheckOwnKnowledge $check): void
    {
        $check->handle($this->shopId, $this->rowId);
    }
}
