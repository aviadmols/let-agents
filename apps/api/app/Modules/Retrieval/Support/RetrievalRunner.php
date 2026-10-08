<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\MatchProducts;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Runs\Models\Run;

final class RetrievalRunner implements RunsRetrieval
{
    public function index(string $shopId): Run
    {
        return app(BuildIndex::class)->handle($shopId);
    }

    public function images(string $shopId): Run
    {
        return app(BuildImageIndex::class)->handle($shopId);
    }

    public function match(string $shopId, ?array $productIds = null): Run
    {
        return app(MatchProducts::class)->handle($shopId, $productIds);
    }
}
