<?php

namespace App\Modules\Improvement\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\WritesPosts;
use App\Modules\Improvement\Jobs\DraftPostJob;
use App\Modules\Improvement\Models\ImprovementPostWrite;
use App\Modules\Improvement\Models\ImprovementProposal;
use Illuminate\Support\Facades\Cache;

/**
 * "Write a dedicated post" on a shopper's question, from the questions screen: a content-gap suggestion
 * whose draft is written now (DraftPostJob) and waits in the suggestions for the team's approval, as
 * one made from a daily review does. Within the month's posts.
 */
final class QuestionPosts implements WritesPosts
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function postFromQuestion(string $shopId, string $question): string
    {
        $question = trim(mb_substr($question, 0, 300));
        $fingerprint = hash('sha256', ImprovementProposal::CONTENT_GAP.'|asked:'.mb_strtolower($question));

        $post = $this->tenant->run($shopId, function () use ($shopId, $question, $fingerprint): ?ImprovementProposal {
            if (ImprovementProposal::query()->where('fingerprint', $fingerprint)->exists()) {
                return null;
            }

            if (ImprovementPostWrite::thisMonth() >= (int) Settings::get('improvement.posts_per_month', $shopId)) {
                return new ImprovementProposal;
            }

            return ImprovementProposal::query()->create([
                'shop_id' => $shopId,
                'review_id' => null,
                'kind' => ImprovementProposal::CONTENT_GAP,
                'title' => $question,
                'detail' => ['topic' => $question, 'why' => (string) __('improvement::ui.post.question_why')],
                'evidence' => ['question' => $question],
                'fingerprint' => $fingerprint,
                'status' => ImprovementProposal::PENDING,
                'proposed_by' => 'team',
            ]);
        });

        if ($post === null) {
            return 'exists';
        }

        if (! $post->exists) {
            return 'cap';
        }

        Cache::put(DraftPostJob::WRITING.$post->id, now()->timestamp, now()->addSeconds(DraftPostJob::WRITING_SECONDS));
        DraftPostJob::dispatch($shopId, $post->id);

        return 'writing';
    }
}
