<?php

namespace App\Modules\Assistant\Jobs;

use App\Modules\Assistant\Actions\PrepareStoreAnswers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** The store's questions answered ahead of shoppers (PrepareStoreAnswers): after what its pages repeat was read. */
final class PrepareStoreAnswersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A dozen questions, each written and checked by a model. */
    public int $timeout = 900;

    public int $tries = 1;

    /** One preparation of a shop at a time. */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $shopId) {}

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(PrepareStoreAnswers $prepare): void
    {
        $prepare->handle($this->shopId);
    }
}
