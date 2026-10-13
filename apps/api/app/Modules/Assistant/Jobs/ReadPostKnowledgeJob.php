<?php

namespace App\Modules\Assistant\Jobs;

use App\Modules\Assistant\Actions\ReadPostKnowledge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** What the shop's posts say about the store, read when the team asks for it on the knowledge screen. */
final class ReadPostKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** One reading of a shop at a time; a second press waits for it. */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $shopId) {}

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(ReadPostKnowledge $posts): void
    {
        $posts->handle($this->shopId);
    }
}
