<?php

namespace App\Modules\Taxonomy\Jobs;

use App\Modules\Taxonomy\Actions\ReadRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A rule the shop wrote on the knowledge screen, read into a plan in the background. */
final class ReadRuleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    /** Seconds before each try again, after a busy model. */
    private const WAIT = [60, 300];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $shopId, public readonly string $ruleId) {}

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->ruleId;
    }

    public function handle(ReadRule $read): void
    {
        $last = $this->attempts() >= $this->tries;
        $read->handle($this->shopId, $this->ruleId, $last);
        // the model was busy: the rule stays "being read" and is read again a while later
        if ($read->later && ! $last) {
            $this->release(self::WAIT[min($this->attempts() - 1, count(self::WAIT) - 1)]);
        }
    }
}
