<?php

namespace App\Modules\Enrichment\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\LookUpOnTheWeb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One product's missing details asked of the web, in the background: a search takes a minute. */
final class LookUpOnTheWebJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param  list<string>  $attributeKeys */
    public function __construct(
        public readonly string $shopId,
        public readonly string $productId,
        public readonly array $attributeKeys,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('long');
    }

    public function handle(TenantContext $tenant, LookUpOnTheWeb $lookUp): void
    {
        $tenant->run($this->shopId, fn () => $lookUp->handle($this->productId, $this->attributeKeys, $this->userId));
    }
}
