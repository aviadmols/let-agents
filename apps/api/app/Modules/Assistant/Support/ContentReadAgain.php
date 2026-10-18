<?php

namespace App\Modules\Assistant\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ReadsContentAgain;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Jobs\ReadPostKnowledgeJob;

/** The scan summary's "scan again" for a page or post: it is read first, by the post reader, now. */
final class ContentReadAgain implements ReadsContentAgain
{
    public function __construct(private readonly TenantContext $tenant, private readonly BuildShopKnowledge $knowledge) {}

    public function readAgain(string $shopId, string $contentId): void
    {
        $this->tenant->run($shopId, function () use ($contentId): void {
            $this->knowledge->forget($contentId);
            $this->knowledge->want($contentId);
        });
        ReadPostKnowledgeJob::dispatch($shopId);
    }
}
