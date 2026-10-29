<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\OpenWords;
use App\Modules\Search\Support\StoreFamilies;
use App\Modules\Search\Support\StoreWords;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * The store's word families settled without the team (Aviad, 29.10: 69 families waited for an approval the team had no
 * reason to give, and 40 searched words for a family): a strong model of the other family than the one that wrote the
 * families judges each written family (all of it the same thing, part of it, or none) and each word shoppers searched
 * that no family holds (the same thing as a family, as one of the store's words that look like it, or as none). Code
 * keeps only what it gave, and saves the judge's word as the team's would be (ChooseWordFamily), marked as the judge's:
 * a family approved or trimmed, one wrong kept apart, a word joined or set aside. The team's own families are never
 * judged; a word may join one. The same input is judged once a week at most. After the families are written, nightly;
 * and from the screen, on request.
 */
final class SettleWordFamilies
{
    public const AGENT = 'search.family_judge';

    public const ACTION = 'search.settle_families';

    public const PROMPT_VERSION = 1;

    private const PROMPTS = __DIR__.'/../Prompts/';

    /** The same families and words are judged again after this long, at the soonest. */
    private const JUDGED_DAYS = 7;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly ChooseWordFamily $choose,
    ) {}

    /** Null when nothing waits (off, no families or words to judge, or the same judged lately). */
    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Schedule, bool $force = false): ?Run
    {
        if (! Features::enabled('search.word_families', $shopId) || ! Features::enabled('search.word_families_auto', $shopId)) {
            return null;
        }
        $input = $this->tenant->run($shopId, fn (): ?array => $this->input($shopId));
        if ($input === null) {
            return null;
        }
        $key = 'search:families-judged:'.$shopId.':'.hash('sha256', (string) json_encode($input, JSON_UNESCAPED_UNICODE));
        if (! $force && Cache::has($key)) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['families' => count($input['families']), 'words' => count($input['words'])],
            work: fn (RunContext $run) => $this->tenant->run($shopId, function () use ($run, $shopId, $input, $key): void {
                if ($this->settle($run, $shopId, $input)) {
                    Cache::put($key, true, now()->addDays(self::JUDGED_DAYS));
                }
            }),
        );
    }

    /**
     * What waits: the written families no one decided on, the team's (to join only), and the searched words no family
     * holds with the store's words that look like each. Null when neither families nor words wait.
     *
     * @return array{store: string, language: string, families: list<array{id: string, words: list<string>}>, team: list<array{id: string, words: list<string>}>, words: list<array{id: string, word: string, searches: int, near: list<string>}>}|null
     */
    private function input(string $shopId): ?array
    {
        $waiting = array_slice(StoreFamilies::waiting(), 0, max(1, (int) Settings::get('search.family_judge_families_max')));
        $index = LoadedIndex::for($shopId);
        $open = OpenWords::of(StoreFamilies::all(), $index === null ? [] : StoreWords::of($shopId, $index)[0],
            (int) Settings::get('search.families_open_days', $shopId), max(1, (int) Settings::get('search.family_judge_words_max')));

        if ($waiting === [] && $open === []) {
            return null;
        }
        $shop = Shop::query()->whereKey($shopId)->first(['name', 'content_locale']);

        return [
            'store' => (string) ($shop?->name ?? ''),
            'language' => (string) ($shop?->content_locale ?: 'he'),
            'families' => array_map(fn (int $i, array $words): array => ['id' => 'f'.($i + 1), 'words' => $words], array_keys($waiting), $waiting),
            'team' => array_map(fn (array $f): array => ['id' => 't'.$f['id'], 'words' => $f['words']], StoreFamilies::approved()),
            'words' => array_map(fn (int $i, array $w): array => ['id' => 'w'.($i + 1), 'word' => $w['word'], 'searches' => $w['searches'], 'near' => $w['near']], array_keys($open), $open),
        ];
    }

    /**
     * @param  array{store: string, language: string, families: list<array{id: string, words: list<string>}>, team: list<array{id: string, words: list<string>}>, words: list<array{id: string, word: string, searches: int, near: list<string>}>}  $input
     * @return bool whether the judge answered
     */
    private function settle(RunContext $run, string $shopId, array $input): bool
    {
        $judge = self::role('family_judge');
        $writer = self::role('resolve');
        if ($judge['provider'] === null) {
            $run->fail('search::runs.resolve_no_provider');

            return false;
        }
        // what one family wrote, the other judges
        if ($writer['provider'] !== null && $judge['provider']->family() === $writer['provider']->family()) {
            $run->fail('search::runs.family_judge_same_family', ['family' => $judge['provider']->label()]);

            return false;
        }

        try {
            $reply = $this->ask($run, $judge, $input);
        } catch (SpendCapReached|ModelCallFailed $e) {
            $run->fail('search::runs.family_judge_failed', [], $e->getMessage());

            return false;
        }

        $counts = ['approved' => 0, 'trimmed' => 0, 'wrong' => 0, 'joined' => 0, 'paired' => 0, 'hidden' => 0];
        // the families, each as the judge kept it: its own words only, as written
        $families = [];
        foreach ($input['families'] as $family) {
            $families[$family['id']] = $family['words'];
        }
        $choices = [];
        foreach ((array) ($reply->data['families'] ?? []) as $answer) {
            $id = is_array($answer) ? (string) ($answer['id'] ?? '') : '';
            if (! isset($families[$id]) || array_key_exists($id, $choices)) {
                continue;
            }
            $keys = array_flip(array_map(fn ($w): string => HebrewSearch::normalize((string) $w), (array) ($answer['keep'] ?? [])));
            $keep = array_values(array_filter($families[$id], fn (string $w): bool => isset($keys[HebrewSearch::normalize($w)])));
            if (count($keep) >= 2) {
                $choices[$id] = $this->choose->handle($shopId, SearchFamilyChoice::APPROVED, $keep, auto: true);
                $counts[count($keep) === count($families[$id]) ? 'approved' : 'trimmed']++;
            } else {
                $this->choose->handle($shopId, SearchFamilyChoice::REJECTED, $families[$id], auto: true);
                $choices[$id] = null;
                $counts['wrong']++;
            }
        }

        // the searched words: into the family they are the same thing as, beside the store's word, or set aside
        $words = [];
        foreach ($input['words'] as $word) {
            $words[$word['id']] = $word;
        }
        $answered = [];
        foreach ((array) ($reply->data['words'] ?? []) as $answer) {
            $id = is_array($answer) ? (string) ($answer['id'] ?? '') : '';
            if (! isset($words[$id]) || isset($answered[$id])) {
                continue;
            }
            $answered[$id] = true;
            $word = $words[$id]['word'];
            $family = (string) ($answer['family'] ?? '');
            $with = HebrewSearch::normalize((string) ($answer['with'] ?? ''));
            $near = array_values(array_filter($words[$id]['near'], fn (string $n): bool => $with !== '' && HebrewSearch::normalize($n) === $with))[0] ?? null;

            if ($family !== '' && ($choice = $this->familyOf($family, $choices)) !== null) {
                $this->choose->handle($shopId, SearchFamilyChoice::APPROVED, [...(array) $choice->words, $word], (string) $choice->id, auto: (bool) $choice->auto);
                $counts['joined']++;
            } elseif ($near !== null) {
                $choice = $this->holding($near);
                $choice === null
                    ? $this->choose->handle($shopId, SearchFamilyChoice::APPROVED, [$near, $word], auto: true)
                    : $this->choose->handle($shopId, SearchFamilyChoice::APPROVED, [...(array) $choice->words, $word], (string) $choice->id, auto: (bool) $choice->auto);
                $counts['paired']++;
            } elseif ($family === '' && $with === '') {
                $this->choose->handle($shopId, SearchFamilyChoice::HIDDEN, [$word], auto: true);
                $counts['hidden']++;
            }
        }

        $run->output($counts + ['families' => count($input['families']), 'words' => count($input['words'])])
            ->summary('search::runs.families_settled', array_map('strval', $counts));

        return true;
    }

    /**
     * The family a word joins: one the judge just kept ("f3"), or the team's ("t" and its id), as it is now.
     *
     * @param  array<string, SearchFamilyChoice|null>  $choices
     */
    private function familyOf(string $id, array $choices): ?SearchFamilyChoice
    {
        if (array_key_exists($id, $choices)) {
            return $choices[$id] === null ? null : $choices[$id]->fresh();
        }

        return str_starts_with($id, 't') ? SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->find(substr($id, 1)) : null;
    }

    /** The approved family that holds one of the store's words, when one does. */
    private function holding(string $word): ?SearchFamilyChoice
    {
        $key = HebrewSearch::normalize($word);
        foreach (StoreFamilies::approved() as $family) {
            if (in_array($key, array_map(fn (string $w): string => HebrewSearch::normalize($w), $family['words']), true)) {
                return SearchFamilyChoice::query()->find($family['id']);
            }
        }

        return null;
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     *
     * @throws SpendCapReached|ModelCallFailed
     */
    private function ask(RunContext $run, array $role, array $input): ModelReply
    {
        $file = self::PROMPTS.'word_families_judge.v'.self::PROMPT_VERSION;
        $system = (string) file_get_contents($file.'.md');
        $schema = json_decode((string) @file_get_contents($file.'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get('search.family_judge_input_usd_per_million');
        $out = (float) Settings::get('search.family_judge_output_usd_per_million');
        $max = (int) Settings::get('search.family_judge_max_output_tokens');

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
        $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
            ? $this->chat->structured($role['provider'], $role['model'], $system, $user, $schema, $max)
            : $this->chat->json($role['provider'], $role['model'], $system, $user, $max);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private static function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("search.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("search.{$name}_model"))];
    }
}
