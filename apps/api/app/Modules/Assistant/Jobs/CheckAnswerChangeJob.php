<?php

namespace App\Modules\Assistant\Jobs;

use App\Modules\Assistant\Actions\CheckAnswerChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A change the team wants in how the store answers, checked while the team waits on the screen: a model's reading,
 * then a few questions answered again with it (CheckAnswerChange). Once: asked again, it is the team's to start again.
 */
final class CheckAnswerChangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A reading and up to a handful of answers, each with its own check. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $checkId) {}

    public function handle(CheckAnswerChange $check): void
    {
        $check->run($this->checkId);
    }

    /** Stopped on the way (a timeout, an error): the team is told it could not be checked now, never left waiting. */
    public function failed(?\Throwable $e = null): void
    {
        app(CheckAnswerChange::class)->failed($this->checkId);
    }
}
