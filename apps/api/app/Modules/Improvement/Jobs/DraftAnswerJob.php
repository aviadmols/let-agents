<?php

namespace App\Modules\Improvement\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\DraftAnswer;
use App\Modules\Improvement\Models\ImprovementProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Writes the answer for one question on the long queue; the screen shows it when it is there. */
final class DraftAnswerJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Where the screen reads that a draft was asked for and has not arrived. */
    public const WRITING = 'improvement:answering:';

    public const WRITING_SECONDS = 300;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public readonly string $shopId, public readonly string $proposalId)
    {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->proposalId;
    }

    public function handle(DraftAnswer $draft, TenantContext $tenant): void
    {
        try {
            $proposal = $tenant->run($this->shopId, fn (): ?ImprovementProposal => ImprovementProposal::query()->find($this->proposalId));

            if ($proposal !== null) {
                $draft->handle($proposal);
            }
        } finally {
            Cache::forget(self::WRITING.$this->proposalId);
        }
    }
}
