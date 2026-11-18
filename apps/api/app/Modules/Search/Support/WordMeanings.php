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

    /**
     * v2: the word said plainly in the store's language first, then whether the store's word names exactly that, and only
     * then is it read as the store's word: v1 read "נשים" as "unisex" in an English store that writes no "women" (Jean
     * Rachel, 16.11), and men's necklaces were shown for women's. Any word, any language: nothing listed in code.
     *
     * v3: letters struck at random are no word, and are read as none: v2 read "גדששד" as the store's "אחר", and 22 products
     * that say "אחר" were shown for it (Gueta, 17.11).
     *
     * v4: the translation is always in the store's language and letters, the language told by its name: v3 left a Hebrew
     * word in Hebrew in an English store two times in three ("אדומה" stayed "אדומה", "שימלה" became "שמלה"), so "שימלה אדומה"
     * read no "dress" and no "red" (Me369, 18.11). A reply still written in the shopper's letters is asked once more.
     */
    public const PROMPT = 'word_meaning.v4';

    /**
     * The gate's version, beside the prompt's: "+forms" also takes what the word plainly means when the store writes it in
     * some form (formIn). A "none" read before it is read again once; a word found stays.
     */
    private const GATE = '+forms';

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
        // "none" was read in the store's language: a store whose language changed reads its words again (Jean Rachel, 16.11:
        // built in Hebrew, then English, its Hebrew "צמיד" kept the "none" read when the store's language was Hebrew). A
        // Hebrew store's hash is the words' alone, as it was, so nothing it read is read again.
        $hash = $locale === 'he' ? $hash : substr(hash('sha256', $hash.'|'.$locale), 0, 20);
        // and the words most of its products say, a store's own word too ("shoes" in a shoe store); a store that has some
        // reads its "none" again once
        $subjects = array_values(array_diff(StoreWords::subjects($shopId, $index), $words));
        if ($subjects !== []) {
            $words = [...$subjects, ...$words];
            $hash = substr(hash('sha256', $hash.'|'.implode('|', $subjects)), 0, 20);
        }
        $byKey = [];
        foreach ($words as $w) {
            $byKey[HebrewSearch::normalize($w)] ??= $w;
        }

        $saved = SearchWordMeaning::query()->where('word', $word)->first();
        // an answer read with another version of the question is read again (once, within the day's words)
        $read = (string) $saved?->model;
        if ($saved !== null && ! str_ends_with($read, '@'.self::PROMPT) && ! str_ends_with($read, '@'.self::PROMPT.self::GATE)
            // a word an older version found stays found: only its "none" is read again
            && ! ($saved->term !== null && isset($byKey[HebrewSearch::normalize($saved->term)]))) {
            $saved = null;
        }
        if ($saved !== null) {
            if ($saved->term !== null && isset($byKey[HebrewSearch::normalize($saved->term)])) {
                return $saved->term;
            }
            if ($saved->term === null && $saved->words_hash === $hash && str_ends_with($read, self::GATE)) {
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
        $user = (string) json_encode(['word' => $word, 'language' => ['en' => 'English'][$locale] ?? 'Hebrew', 'store_words' => $words], JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get('search.word_reader_input_usd_per_million');
        $out = (float) Settings::get('search.word_reader_output_usd_per_million');
        $max = (int) Settings::get('search.word_reader_max_output_tokens');
        $schema = json_decode((string) @file_get_contents(__DIR__.'/../Prompts/'.self::PROMPT.'.schema.json'), true);

        // the store's letters: a translation with none of them, of a word typed in other letters, was not translated
        $own = $locale === 'he' ? '/[א-ת]/u' : '/[a-z]/i';
        foreach ([1, 2] as $try) {
            try {
                $this->spend->assertCanSpend(forShopper: true, estimatedUsd: ((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
                $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
                    ? $this->chat->structured($provider, $model, $system, $user, $schema, $max)
                    : $this->chat->json($provider, $model, $system, $user, $max);
            } catch (SpendCapReached|ModelCallFailed) {
                return null;
            }
            Run::recordUsage($shopId, self::AGENT, self::ACTION, $provider->value, $model, $reply->inputTokens, $reply->outputTokens, $reply->costUsd($in, $out));
            $translation = trim((string) ($reply->data['translation'] ?? ''));
            if ($translation === '' || preg_match($own, $translation) === 1 || preg_match($own, $word) === 1) {
                break;
            }
        }

        // code's gate: a word of the store's own, as the store writes it, that names exactly what the shopper's word does
        // (same), or none
        $said = trim((string) ($reply->data['term'] ?? ''));
        // no word at all (v3: an empty translation): nothing of the store's is its reading, whatever term came with it
        $said = trim((string) ($reply->data['translation'] ?? '')) === '' ? '' : $said;
        $term = $said === '' || ($reply->data['same'] ?? false) !== true ? null : ($byKey[HebrewSearch::normalize($said)] ?? self::formIn($said, $words));
        // ...or what the word plainly means, when the store writes that very word in some form: "צמיד" is "bracelet", the store
        // writes "bracelets", and the model called it no match (Jean Rachel, 16.11; "shoes", "hoodie", "towels" the same)
        $term ??= self::formIn(trim((string) ($reply->data['translation'] ?? '')), $words);
        $term = $term !== null && HebrewSearch::formKey($term) === HebrewSearch::formKey($word) ? null : $term;
        SearchWordMeaning::query()->updateOrCreate(['shop_id' => $shopId, 'word' => $word], ['term' => $term, 'words_hash' => $hash, 'model' => mb_substr($model.'@'.self::PROMPT.self::GATE, 0, 120)]);

        return $term;
    }

    /**
     * The store's word that is this one in some form (HebrewSearch::formKey: "bracelet" for the store's "bracelets"), as
     * the store writes it, the most said first; null when the store writes no form of it.
     *
     * @param  list<string>  $words  the store's words
     */
    private static function formIn(string $plain, array $words): ?string
    {
        if (mb_strlen($plain) < 2) {
            return null;
        }
        $key = HebrewSearch::formKey($plain);
        foreach ($words as $w) {
            if (HebrewSearch::normalize($w) === HebrewSearch::normalize($plain)) {
                return $w;
            }
        }
        foreach ($words as $w) {
            if (HebrewSearch::formKey($w) === $key) {
                return $w;
            }
        }

        return null;
    }
}
