<?php

namespace App\Modules\Assistant\Jobs;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** What the shop says about itself, read again: after a sync, at night, or when the team asks. */
final class BuildShopKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /** One reading of a shop at a time; a second sync in the same minutes waits for the next. */
    public int $uniqueFor = 300;

    /** A dozen page reads: the quick queue, never behind a picture scan. */
    public function __construct(public readonly string $shopId) {}

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(BuildShopKnowledge $knowledge, TenantContext $tenant): void
    {
        if (Features::enabled('assistant.knowledge', $this->shopId)) {
            $tenant->run($this->shopId, function () use ($knowledge): void {
                $knowledge->repeated($this->shopId);
                $knowledge->team();
            });
        }

        // With what the pages repeat read, the store's own questions are answered ahead of shoppers.
        PrepareStoreAnswersJob::dispatch($this->shopId);
    }
}
