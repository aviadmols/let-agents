<?php

namespace App\Modules\Search\Support;

use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchWordMeaning;

/**
 * The words shoppers searched in a store lately that no word family holds ("פנטזיה", "שבי"), the most searched first:
 * how many searches said each, how many found nothing, and the store's words it may belong with (those of its root,
 * "כפתור": כפתורים; the word the search read it as, "שבי": חטופים). Not a colour, not a filler, not one the team set
 * aside. Read inside the store's tenant context. Code only.
 */
final class OpenWords
{
    /** The latest searches read, at most. */
    private const SEARCHES = 5000;

    /** Store words offered beside a searched word, at most. */
    private const NEAR = 4;

    /**
     * @param  list<list<string>>  $families  the store's families (StoreFamilies::all)
     * @param  list<string>  $storeWords  the store's words (StoreWords)
     * @return list<array{word: string, searches: int, empty: int, near: list<string>}>
     */
    public static function of(array $families, array $storeWords, int $days, int $max): array
    {
        $held = [];
        foreach ($families as $family) {
            foreach ($family as $word) {
                $plain = HebrewSearch::normalize((string) $word);
                $held[$plain] = true;
                $held[HebrewSearch::stem($plain)] = true;
            }
        }
        $hidden = StoreFamilies::hidden();

        $words = [];
        $logs = SearchLog::query()->where('created_at', '>=', now()->subDays($days))->orderByDesc('id')->limit(self::SEARCHES)->get(['query', 'results']);
        foreach ($logs as $log) {
            foreach (array_unique(explode(' ', HebrewSearch::normalize($log->query))) as $word) {
                if (mb_strlen($word) < 2 || preg_match('/^\p{L}+$/u', $word) !== 1 || HebrewSearch::isFiller($word) || HebrewSearch::colorOf($word) !== null
                    || isset($held[$word]) || isset($held[HebrewSearch::stem($word)]) || isset($hidden[$word])) {
                    continue;
                }
                $words[$word] ??= ['searches' => 0, 'empty' => 0];
                $words[$word]['searches']++;
                $words[$word]['empty'] += $log->results === 0 ? 1 : 0;
            }
        }
        uksort($words, fn (string $a, string $b): int => [$words[$b]['searches'], $words[$b]['empty'], $a] <=> [$words[$a]['searches'], $words[$a]['empty'], $b]);
        $words = array_slice($words, 0, $max, true);

        // the store's words of the same root, and what the search read a word it does not know as
        $roots = [];
        foreach ($storeWords as $storeWord) {
            $plain = HebrewSearch::normalize($storeWord);
            if (! str_contains($plain, ' ')) {
                $roots[HebrewSearch::skeleton(HebrewSearch::stem($plain))][] = $storeWord;
            }
        }
        $read = [];
        $asked = array_map('strval', array_keys($words));
        foreach (SearchWordMeaning::query()->whereIn('word', [...$asked, ...array_map(fn (string $w): string => HebrewSearch::withFinals($w), $asked)])->whereNotNull('term')->get(['word', 'term']) as $meaning) {
            $read[HebrewSearch::normalize($meaning->word)] = (string) $meaning->term;
        }

        $out = [];
        foreach ($words as $word => $count) {
            $word = (string) $word;
            $near = [];
            foreach ([...(isset($read[$word]) ? [$read[$word]] : []), ...($roots[HebrewSearch::skeleton(HebrewSearch::stem($word))] ?? [])] as $candidate) {
                $key = HebrewSearch::normalize($candidate);
                if ($key !== $word && ! isset($near[$key])) {
                    $near[$key] = $candidate;
                }
            }
            $out[] = ['word' => HebrewSearch::withFinals($word), 'searches' => $count['searches'], 'empty' => $count['empty'], 'near' => array_slice(array_values($near), 0, self::NEAR)];
        }

        return $out;
    }
}
