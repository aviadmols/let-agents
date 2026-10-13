<?php

namespace App\Modules\Improvement\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\SendPostToStore;
use App\Modules\Improvement\Models\ImprovementPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Sends one published post to the store's own blog, off the request; the screen shows where it went. */
final class SendPostJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Where the screen reads that a post is on its way to the store. */
    public const SENDING = 'improvement:sending:';

    public const SENDING_SECONDS = 300;

    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public readonly string $shopId, public readonly string $postId) {}

    public function uniqueId(): string
    {
        return $this->postId;
    }

    public function handle(SendPostToStore $send, TenantContext $tenant): void
    {
        try {
            $tenant->run($this->shopId, function () use ($send): void {
                $post = ImprovementPost::query()->find($this->postId);

                if ($post !== null && $post->status === ImprovementPost::PUBLISHED) {
                    $send->handle($post);
                }
            });
        } finally {
            Cache::forget(self::SENDING.$this->postId);
        }
    }
}
