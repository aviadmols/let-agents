<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Tenancy\Models\Shop;
use Throwable;

/**
 * The baseline: real questions shoppers asked, from every shop, kept with what the writer was given for
 * them now (frozen), so every change to a prompt, a model or the search is measured on the same ones.
 * Chosen in code: each question once (by its key), the most asked first, a share of each outcome so the
 * set holds answers, refusals and questions about other things, never a support question. A question
 * already in a shop's cases is not added again. No model is called; the snapshots are code.
 */
final class BuildQualityBaseline
{
    /** The share of each outcome in a shop's part of the baseline. */
    private const MIX = [AssistantAnswer::ANSWERED => 0.6, 'unanswered' => 0.3, AssistantAnswer::OUT_OF_SCOPE => 0.1];

    public function __construct(
        private readonly AnswerSiteQuestion $answers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>|null  $shopIds  null: every shop with questions
     * @return array<string, int> shop id => cases added
     */
    public function handle(int $perShop = 20, ?array $shopIds = null, int $days = 90): array
    {
        $shops = $this->tenant->runUnscoped(fn () => Shop::query()->when($shopIds !== null, fn ($q) => $q->whereIn('id', $shopIds))->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $added = [];

        foreach ($shops as $shopId) {
            $added[$shopId] = $this->tenant->run($shopId, fn (): int => $this->shop($shopId, $perShop, $days));
        }

        return array_filter($added);
    }

    private function shop(string $shopId, int $perShop, int $days): int
    {
        $have = AssistantQualityCase::query()->pluck('question')->map(fn ($q): string => Question::key((string) $q))->flip();
        $asks = AssistantSearchAsk::query()->where('created_at', '>=', now()->subDays($days))->where('question', '!=', '[support]')
            ->latest('created_at')->limit(3000)->get(['id', 'question', 'products', 'thread', 'outcome']);
        $groups = [];

        foreach ($asks->groupBy(fn (AssistantSearchAsk $a): string => Question::key((string) $a->question)) as $key => $same) {
            if (isset($have[$key]) || mb_strlen((string) $same->first()->question) < 4) {
                continue;
            }

            $outcome = (string) $same->first()->outcome;
            $bucket = in_array($outcome, [AssistantAnswer::NO_INFO, 'no_match'], true) ? 'unanswered' : $outcome;

            if (isset(self::MIX[$bucket])) {
                $groups[$bucket][] = ['ask' => $same->first(), 'asked' => $same->count()];
            }
        }

        $chosen = [];
        foreach (self::MIX as $bucket => $share) {
            $list = $groups[$bucket] ?? [];
            usort($list, fn (array $a, array $b): int => $b['asked'] <=> $a['asked']);
            array_push($chosen, ...array_slice($list, 0, (int) ceil($perShop * $share)));
        }

        $added = 0;

        foreach (array_slice($chosen, 0, $perShop) as $pick) {
            /** @var AssistantSearchAsk $ask */
            $ask = $pick['ask'];

            // A reply ("יש משהו עם זהב צהוב?") is kept with its conversation and asked again on today's pages: what it
            // stands for is written by the topic check each time, so there is nothing of it to freeze.
            $thread = array_values(array_filter((array) $ask->thread, fn ($t): bool => is_array($t) && isset($t['q'], $t['a'])));

            try {
                $snapshot = $thread === [] ? $this->answers->snapshot($shopId, (string) $ask->question, (array) $ask->products) : null;
            } catch (Throwable $e) {
                report($e);
                $snapshot = null;
            }

            AssistantQualityCase::query()->create([
                'shop_id' => $shopId, 'ask_id' => $ask->id, 'kind' => AssistantQualityCase::BASELINE, 'question' => mb_substr((string) $ask->question, 0, 500),
                'products' => (array) $ask->products, 'thread' => $thread ?: null, 'expect' => [], 'snapshot' => $snapshot, 'snapshot_at' => $snapshot === null ? null : now(),
                'note' => 'asked '.$pick['asked'].'×, '.$ask->outcome.($thread === [] ? '' : ', a reply'),
            ]);
            $added++;
        }

        return $added;
    }
}
