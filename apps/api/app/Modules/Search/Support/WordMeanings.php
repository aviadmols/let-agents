<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchWordMeaning;
use App\Modules\Tenancy\Models\Shop;

/**
 * A word shoppers typed that the store does not write, read as the store's word it means ("מפוספס": "פסים", "נעלי ספורט":
 * "סניקרס"), or none. Vectors read Hebrew words by their letters, not their meaning (Story, 26.10: "מפוספסת" nearer
 * "מחוספסת" than "חולצה עם פסים" on three models), so a small model chooses among the store's own words (StoreWords),
 * and code keeps only a word of that list. Once per word: kept, and read again only when the store's words change and
 * the answer was none, or the word it named is gone. Asked at a pause or on Enter only, never on a keystroke; paid,
 * capped and counted like any model call.
 */
final class WordMeanings
{
    public const AGENT = 'search.word_reader';

    public const ACTION = 'search.read_words';

    public const PROMPT = 'word_meaning.v1';

    public function __construct(
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    /** @param  array<string, mixed>  $index  the loaded index. Call inside the shop's tenant. */
    public function read(string $shopId, array $index, string $word): ?string
    {
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
        $word = mb_substr(trim($word), 0, 80);
        if (mb_strlen($word) < 2) {
            return null;
        }
        [$words, $hash] = StoreWords::of($shopId, $index);
        if ($words === []) {
            return null;
        }
        $byKey = [];
        foreach ($words as $w) {
            $byKey[HebrewSearch::normalize($w)] ??= $w;
        }

        $saved = SearchWordMeaning::query()->where('word', $word)->first();
        if ($saved !== null) {
            if ($saved->term !== null && isset($byKey[HebrewSearch::normalize($saved->term)])) {
                return $saved->term;
            }
            if ($saved->term === null && $saved->words_hash === $hash) {
                return null;
            }
        }

        // at most this many new words a day for a store: a bot typing made-up words never runs up its bill
        if (SearchWordMeaning::query()->where('updated_at', '>=', now()->startOfDay())->count() >= (int) Settings::get('search.word_reader_daily_max', $shopId)) {
            return null;
        }

        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('search.word_reader_provider'))));
        $model = trim((string) Settings::get('search.word_reader_model'));
        $system = (string) @file_get_contents(__DIR__.'/../Prompts/'.self::PROMPT.'.md');
        if ($provider === null || $model === '' || $system === '') {
            return null;
        }
        $user = (string) json_encode(['word' => $word, 'language' => $locale, 'store_words' => $words], JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get('search.word_reader_input_usd_per_million');
        $out = (float) Settings::get('search.word_reader_output_usd_per_million');
        $max = (int) Settings::get('search.word_reader_max_output_tokens');
        $schema = json_decode((string) @file_get_contents(__DIR__.'/../Prompts/'.self::PROMPT.'.schema.json'), true);

        try {
            $this->spend->assertCanSpend(forShopper: true, estimatedUsd: ((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
            $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
                ? $this->chat->structured($provider, $model, $system, $user, $schema, $max)
                : $this->chat->json($provider, $model, $system, $user, $max);
        } catch (SpendCapReached|ModelCallFailed) {
            return null;
        }
        Run::recordUsage($shopId, self::AGENT, self::ACTION, $provider->value, $model, $reply->inputTokens, $reply->outputTokens, $reply->costUsd($in, $out));

        // code's gate: a word of the store's own, as the store writes it, or none
        $said = trim((string) ($reply->data['term'] ?? ''));
        $term = $said === '' ? null : ($byKey[HebrewSearch::normalize($said)] ?? null);
        $term = $term !== null && HebrewSearch::normalize($term) === HebrewSearch::normalize($word) ? null : $term;
        SearchWordMeaning::query()->updateOrCreate(['shop_id' => $shopId, 'word' => $word], ['term' => $term, 'words_hash' => $hash, 'model' => $model]);

        return $term;
    }
}
