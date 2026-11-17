<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\Cache;

/**
 * A line the team added on the knowledge screen, checked against the site before the team leans on
 * it: code gathers the store's text nearest the line; a model compares and names, by id, each
 * passage that says otherwise; code keeps only ids it sent and the passage's title. Nothing is
 * checked when the site says nothing near it. The line is the team's and is used either way; the
 * check is shown beside it ("the site says otherwise: ...").
 *
 * The same line against the same passages is never asked twice: the result is kept by its fingerprint.
 *
 * v2 (8a, 17.11): the line is also said to be an instruction to the assistant ("never write a coupon code") or information
 * for shoppers, so an instruction is followed and never told; and it is compared with the team's other lines too (Rocks
 * wrote both "no Shopping IL discount until Sunday" and "a Shopping IL discount until Sunday"). Asked even when the site
 * says nothing near it: what kind of line it is does not depend on the site.
 */
final class CheckOwnKnowledge
{
    public const AGENT = 'assistant.knowledge_checker';

    public const ACTION = 'assistant.check_own_knowledge';

    public const PROMPT_VERSION = 2;

    /** The team's other lines it is compared with, at most. */
    private const TEAM = 20;

    private const PASSAGES = 6;

    private const MAX_PASSAGE = 800;

    private const MAX_WHY = 240;

    private const OUTPUT_TOKENS = 400;

    private const KEEP_DAYS = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
        private readonly Passages $passages,
        private readonly BuildShopKnowledge $knowledge,
    ) {}

    /** Checks the line if it is still the team's, and writes what was found beside it. */
    public function handle(string $shopId, string $rowId): void
    {
        $this->tenant->run($shopId, function () use ($shopId, $rowId): void {
            $row = collect($this->knowledge->ownRows())->firstWhere('id', $rowId);

            if ($row === null || ! Features::enabled('assistant.knowledge', $shopId)) {
                return;
            }

            $others = array_values(array_filter($this->knowledge->ownRows(), fn (array $r): bool => $r['id'] !== $rowId));
            $this->knowledge->checked($rowId, $row['text'], $this->check($shopId, $row['text'], array_slice(array_map(fn (array $r): string => (string) $r['text'], $others), 0, self::TEAM)));
        });
    }

    /**
     * @param  list<string>  $team  the team's other lines
     * @return array{status: string, notes: list<array{title: string, why: string}>, kind?: string}
     */
    private function check(string $shopId, string $line, array $team = []): array
    {
        $passages = [];
        $titles = [];
        $texts = [];

        foreach ($this->passages->near($shopId, $line, ['content'], self::PASSAGES) as $i => $piece) {
            $id = 'p'.($i + 1);
            $passages[] = ['id' => $id, 'title' => (string) $piece['title'], 'text' => mb_substr(trim((string) $piece['text']), 0, self::MAX_PASSAGE)];
            $titles[$id] = (string) $piece['title'];
            // what it says, kept with the note: the team sees the line it is written in, and fixes it there (SourceLines)
            $texts[$id] = mb_substr(trim((string) $piece['text']), 0, self::MAX_PASSAGE);
        }

        foreach (array_values($team) as $i => $other) {
            $titles['t'.($i + 1)] = (string) __('assistant::ui.knowledge.titles.own');
            $texts['t'.($i + 1)] = mb_substr(trim($other), 0, self::MAX_PASSAGE);
        }
        $input = ['line' => $line, 'passages' => $passages, 'team' => array_values(array_map(fn (string $id): array => ['id' => $id, 'text' => $texts[$id]], array_values(array_filter(array_keys($texts), fn (string $id): bool => str_starts_with($id, 't')))))];
        $fingerprint = 'assistant:own_check:'.hash('sha256', self::PROMPT_VERSION.'|'.json_encode($input, JSON_UNESCAPED_UNICODE));

        if (is_array($kept = Cache::get($fingerprint))) {
            return $kept;
        }

        if ($this->usedToday($shopId) >= (int) Settings::get('assistant.team_drafts_per_day', $shopId)) {
            return ['status' => 'later', 'notes' => []];
        }

        $result = ['status' => 'failed', 'notes' => []];

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['line' => $line, 'passages' => count($passages)],
            work: function (RunContext $run) use ($input, $titles, $texts, $passages, &$result): void {
                $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.post_check_provider'))));
                $model = trim((string) Settings::get('assistant.post_check_model'));

                if ($provider === null || $model === '') {
                    $run->fail('assistant::runs.team_draft_no_provider');

                    return;
                }

                $system = (string) file_get_contents(__DIR__.'/../Prompts/own_fact_check.v'.self::PROMPT_VERSION.'.md');
                $schema = json_decode((string) @file_get_contents(__DIR__.'/../Prompts/own_fact_check.v'.self::PROMPT_VERSION.'.schema.json'), true);
                $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $in = (float) Settings::get('assistant.post_check_input_usd_per_million');
                $out = (float) Settings::get('assistant.post_check_output_usd_per_million');

                try {
                    $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + self::OUTPUT_TOKENS * $out) / 1_000_000);
                    $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
                        ? $this->chat->structured($provider, $model, $system, $user, $schema, self::OUTPUT_TOKENS)
                        : $this->chat->json($provider, $model, $system, $user, self::OUTPUT_TOKENS);
                } catch (SpendCapReached) {
                    $run->fail('assistant::runs.spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
                $notes = [];

                // Only a passage that was sent, once, with its title and its words from code; two pieces of one page
                // found saying the same are one note.
                foreach ((array) ($reply->data['conflicts'] ?? []) as $conflict) {
                    $id = is_array($conflict) ? (string) ($conflict['id'] ?? '') : '';
                    $why = is_array($conflict) ? trim((string) ($conflict['why'] ?? '')) : '';
                    $same = isset($titles[$id]) ? $titles[$id].'|'.$why : '';

                    if (isset($titles[$id]) && $why !== '' && ! isset($notes[$same])) {
                        $notes[$same] = ['title' => $titles[$id], 'why' => mb_substr($why, 0, self::MAX_WHY), 'text' => $texts[$id] ?? ''];
                    }
                }

                $kind = in_array($reply->data['kind'] ?? null, ['instruction', 'information'], true) ? (string) $reply->data['kind'] : 'information';
                $status = $notes !== [] ? 'conflict' : ($passages === [] ? 'nothing' : 'ok');
                $result = ['status' => $status, 'notes' => array_values($notes), 'kind' => $kind];
                $run->output($result)->summary('assistant::runs.own_checked', ['conflicts' => (string) count($notes)]);
            },
        );

        if ($result['status'] !== 'failed') {
            Cache::put($fingerprint, $result, now()->addDays(self::KEEP_DAYS));
        }

        return $result;
    }

    private function usedToday(string $shopId): int
    {
        return Run::query()->forShop($shopId)->where('action', self::ACTION)->where('started_at', '>=', now()->startOfDay())->count();
    }
}
