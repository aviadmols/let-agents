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
use App\Modules\Search\Models\SearchWordFamily;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\StoreWords;
use App\Modules\Tenancy\Models\Shop;

/**
 * A store's word families: its own words a shopper means as one ("משובץ", "משובצות", "משבצות" on Vans; "שבי", "חטוף",
 * "חטופים" and "פנטזיה", "מדע בדיוני" on Sela), so a search for one finds what says another, and its chips read them as
 * one. One model family writes them from the store's words (StoreWords), the other checks them, and code keeps only the
 * store's own words, a word in one family at most. After the index is built (the `search index` step: a scan's and the
 * night's); written again only when the store's words change. The index reads them as the store's synonyms.
 */
final class WriteWordFamilies
{
    public const AGENT = 'search.family_writer';

    public const ACTION = 'search.write_families';

    public const PROMPT_VERSION = 1;

    private const PROMPTS = __DIR__.'/../Prompts/';

    /** At most this many words a family, and this many families. */
    private const FAMILY_MAX = 8;

    private const FAMILIES_MAX = 400;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    /** Null when the store's families stay as they are (off, too few words, or its words unchanged). */
    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): ?Run
    {
        if (! Features::enabled('search.word_families', $shopId)) {
            return null;
        }
        LoadedIndex::forget();
        $index = $this->tenant->run($shopId, fn (): ?array => LoadedIndex::for($shopId));
        if ($index === null) {
            return null;
        }
        [$words, $hash] = StoreWords::of($shopId, $index);
        $saved = $this->tenant->run($shopId, fn (): ?SearchWordFamily => SearchWordFamily::query()->first());
        if (count($words) < 10 || ($saved !== null && $saved->words_hash === $hash && $saved->prompt_version === self::PROMPT_VERSION)) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['words' => count($words)],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->write($run, $shopId, $words, $hash)),
        );
    }

    /** @param list<string> $words */
    private function write(RunContext $run, string $shopId, array $words, string $hash): void
    {
        $writer = $this->role('resolve');
        $checker = $this->role('check');
        if ($writer['provider'] === null || $checker['provider'] === null) {
            $run->fail('search::runs.resolve_no_provider');

            return;
        }
        // what one family writes the other checks
        if ($writer['provider']->family() === $checker['provider']->family()) {
            $run->fail('search::runs.resolve_same_family', ['family' => $writer['provider']->label()]);

            return;
        }
        $language = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');

        try {
            $written = $this->ask($run, $writer, 'word_families', ['language' => $language, 'store_words' => $words]);
        } catch (SpendCapReached|ModelCallFailed $e) {
            $run->fail('search::runs.families_failed', [], $e->getMessage());

            return;
        }

        // code's gate: the store's own words, as it writes them, a word in one family at most, two to FAMILY_MAX a family
        $byKey = [];
        foreach ($words as $w) {
            $byKey[HebrewSearch::normalize($w)] ??= $w;
        }
        $families = [];
        $taken = [];
        foreach (array_slice((array) ($written->data['families'] ?? []), 0, self::FAMILIES_MAX) as $family) {
            $kept = [];
            foreach ((array) $family as $word) {
                $key = HebrewSearch::normalize(is_string($word) ? $word : '');
                if ($key !== '' && isset($byKey[$key]) && ! isset($taken[$key]) && ! isset($kept[$key])) {
                    $kept[$key] = $byKey[$key];
                }
            }
            if (count($kept) >= 2) {
                $kept = array_slice($kept, 0, self::FAMILY_MAX, true);
                $taken += $kept;
                $families['f'.(count($families) + 1)] = array_values($kept);
            }
        }

        // the other family checks: a family wrong as a whole goes, a word wrong in it is taken out
        if ($families !== []) {
            try {
                $checked = $this->ask($run, $checker, 'word_families_check', ['language' => $language,
                    'families' => array_map(fn (string $id, array $f): array => ['id' => $id, 'words' => $f], array_keys($families), $families)]);
            } catch (SpendCapReached|ModelCallFailed $e) {
                $run->fail('search::runs.families_failed', [], $e->getMessage());

                return;
            }
            foreach ((array) ($checked->data['drop'] ?? []) as $id) {
                unset($families[(string) $id]);
            }
            foreach ((array) ($checked->data['remove'] ?? []) as $out) {
                $id = (string) ($out['id'] ?? '');
                if (isset($families[$id])) {
                    $families[$id] = array_values(array_filter($families[$id], fn (string $w): bool => HebrewSearch::normalize($w) !== HebrewSearch::normalize((string) ($out['word'] ?? ''))));
                }
            }
            $families = array_filter($families, fn (array $f): bool => count($f) >= 2);
        }

        SearchWordFamily::query()->updateOrCreate(['shop_id' => $shopId], [
            'words_hash' => $hash,
            'families' => array_values($families),
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $writer['model'],
        ]);
        $run->output(['families' => count($families), 'words' => array_sum(array_map('count', $families)), 'sample' => array_slice(array_values($families), 0, 12)])
            ->summary('search::runs.families', ['count' => (string) count($families)]);
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     *
     * @throws SpendCapReached|ModelCallFailed
     */
    private function ask(RunContext $run, array $role, string $prompt, array $input): ModelReply
    {
        $system = (string) file_get_contents(self::PROMPTS.$prompt.'.v'.self::PROMPT_VERSION.'.md');
        $schema = json_decode((string) @file_get_contents(self::PROMPTS.$prompt.'.v'.self::PROMPT_VERSION.'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prices = $role === $this->role('resolve') ? 'resolve' : 'check';
        $in = (float) Settings::get("search.{$prices}_input_usd_per_million");
        $out = (float) Settings::get("search.{$prices}_output_usd_per_million");
        $max = (int) Settings::get('search.families_max_output_tokens');

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
        $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
            ? $this->chat->structured($role['provider'], $role['model'], $system, $user, $schema, $max)
            : $this->chat->json($role['provider'], $role['model'], $system, $user, $max);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("search.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("search.{$name}_model"))];
    }
}
