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

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $shopId, public readonly string $ruleId) {}

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->ruleId;
    }

    public function handle(ReadRule $read): void
    {
        $read->handle($this->shopId, $this->ruleId);
    }
}
