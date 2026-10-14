<?php

namespace App\Modules\Enrichment\Jobs;

use App\Modules\Enrichment\Actions\SuggestFromPicture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A missing look of some products, suggested from their pictures, in the background. */
final class SuggestFromPictureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** @param list<string> $productIds */
    public function __construct(
        public readonly string $shopId,
        public readonly string $vocabularyId,
        public readonly string $attribute,
        public readonly array $productIds,
    ) {
        $this->onQueue('long');
    }

    public function handle(SuggestFromPicture $suggest): void
    {
        $suggest->handle($this->shopId, $this->vocabularyId, $this->attribute, $this->productIds);
    }
}
