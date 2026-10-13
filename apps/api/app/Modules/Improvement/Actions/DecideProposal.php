<?php

namespace App\Modules\Improvement\Actions;

use App\Core\Facades\Features;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Improvement\Jobs\SendPostJob;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Search\Models\SearchSynonym;
use Illuminate\Support\Facades\Cache;

/**
 * The team's decision on a proposal. Approving a synonym puts it into the search (from the next
 * update). Approving a question publishes its answer, as the team left it, as an answer of the
 * site's own: the search offers it and the assistant gives it, with no model in between.
 * Approving a missing topic publishes the post a model drafted and the team edited, as a guide
 * of the store's own, and sends it to the store's own blog as a draft (improvement.post_to_store).
 * Rejecting keeps the proposal, so the review never proposes it again, and
 * takes back what an earlier approval put out. Callers are inside the shop's tenant.
 */
final class DecideProposal
{
    public const TEAM = 'team';

    public function __construct(private readonly PublishPost $posts) {}

    /**
     * @param  string|null  $text  the answer or the post body as the team left it; the draft when null
     * @param  string|null  $title  the post's title as the team left it; the draft's when null
     * @param  array<string, string>|null  $seo  the post's SEO title and description as the team left them
     * @return string|null why it could not be approved (a text key), or null when it was
     */
    public function approve(ImprovementProposal $proposal, ?int $userId, ?string $text = null, ?string $title = null, ?array $seo = null): ?string
    {
        if (! in_array($proposal->status, [ImprovementProposal::PENDING, ImprovementProposal::REFUSED], true)) {
            return null;
        }

        $status = ImprovementProposal::APPROVED;

        if ($proposal->kind === ImprovementProposal::SYNONYM) {
            SearchSynonym::query()->firstOrCreate(
                ['term' => (string) $proposal->detail['term'], 'means' => (string) $proposal->detail['means']],
                ['shop_id' => $proposal->shop_id, 'origin' => 'review'],
            );
            $status = ImprovementProposal::APPLIED;
        } elseif ($proposal->kind === ImprovementProposal::FAQ) {
            $answer = trim((string) ($text ?? $proposal->detail['answer'] ?? ''));

            if (mb_strlen($answer) < 2) {
                return 'improvement::ui.answer_missing';
            }

            // "[לבדוק]": a fact the draft left for the team is not an answer yet.
            if (preg_match('/\[[^\]\n]{1,60}\]/u', $answer) === 1) {
                return 'improvement::ui.answer_placeholder';
            }

            $this->publishAnswer($proposal, mb_substr($answer, 0, 1200));
            $proposal->detail = ['answer' => mb_substr($answer, 0, 1200)] + $proposal->detail;
            $status = ImprovementProposal::APPLIED;
        } elseif ($proposal->kind === ImprovementProposal::CONTENT_GAP) {
            $post = $proposal->post;
            $body = trim((string) ($text ?? $post?->body ?? ''));
            $heading = trim((string) ($title ?? $post?->title ?? ''));

            if ($post === null || $body === '' || $heading === '') {
                return 'improvement::ui.post_missing';
            }

            if ($seo !== null) {
                $post->seo = array_replace((array) $post->seo, array_map(fn ($v): string => mb_substr(trim((string) $v), 0, 170), array_intersect_key($seo, array_flip(['seo_title', 'meta_description']))));
            }

            $this->posts->publish($post, $heading, $body);
            $status = ImprovementProposal::APPLIED;

            if (Features::enabled('improvement.post_to_store', $proposal->shop_id)) {
                self::send($post->shop_id, $post->id);
            }
        }

        $proposal->forceFill(['status' => $status, 'decided_by' => $userId, 'decided_at' => now()])->save();

        return null;
    }

    public function reject(ImprovementProposal $proposal, ?int $userId): void
    {
        if ($proposal->status === ImprovementProposal::APPLIED) {
            if ($proposal->kind === ImprovementProposal::SYNONYM) {
                SearchSynonym::query()->where('term', $proposal->detail['term'])->where('means', $proposal->detail['means'])->where('origin', 'review')->delete();
            } elseif ($proposal->kind === ImprovementProposal::FAQ) {
                AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id')
                    ->where('question_key', AssistantAnswer::keyFor($this->question($proposal)))->where('source', self::TEAM)
                    ->update(['status' => AssistantAnswer::HIDDEN, 'updated_at' => now()]);
            } elseif ($proposal->kind === ImprovementProposal::CONTENT_GAP && $proposal->post instanceof ImprovementPost) {
                $this->posts->unpublish($proposal->post);
            }
        }

        $proposal->forceFill(['status' => ImprovementProposal::REJECTED, 'decided_by' => $userId, 'decided_at' => now()])->save();
    }

    /** A post on its way to the store's own blog, off the request; the screen shows it until it arrives. */
    public static function send(string $shopId, string $postId): void
    {
        Cache::put(SendPostJob::SENDING.$postId, now()->timestamp, now()->addSeconds(SendPostJob::SENDING_SECONDS));
        SendPostJob::dispatch($shopId, $postId);
    }

    /** The answer as one of the site's own: shown to the next shopper who asks, never replaced by a model. */
    private function publishAnswer(ImprovementProposal $proposal, string $answer): void
    {
        $question = $this->question($proposal);
        $asked = array_sum(array_map(fn (array $e): int => (int) ($e['asked'] ?? 0), $proposal->evidence));

        AssistantAnswer::query()->updateOrCreate(
            ['shop_id' => $proposal->shop_id, 'product_id' => null, 'content_id' => null, 'question_key' => AssistantAnswer::keyFor($question)],
            [
                'question' => $question,
                'answer' => $answer,
                'outcome' => AssistantAnswer::ANSWERED,
                'source' => self::TEAM,
                'sources' => null,
                'status' => AssistantAnswer::SHOWN,
                'prompt_version' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'asked_count' => max(1, $asked),
                'last_asked_at' => now(),
            ],
        );
    }

    private function question(ImprovementProposal $proposal): string
    {
        return trim((string) ($proposal->detail['question'] ?? $proposal->title));
    }
}
