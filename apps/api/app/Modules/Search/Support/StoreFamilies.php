<?php

namespace App\Modules\Search\Support;

use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchWordFamily;

/**
 * A store's word families as its search reads them: the ones its team approved, fixed or made, as they are; then the
 * written ones (WriteWordFamilies), with no word the team placed in a family of its own and never two words of a family
 * the team called wrong ("לא נכון"). Written families wait for the team's word but count from the start. Read inside
 * the store's tenant context. Code only.
 */
final class StoreFamilies
{
    /** @return list<list<string>> every family the search reads, the team's first */
    public static function all(): array
    {
        return [...array_column(self::approved(), 'words'), ...self::waiting()];
    }

    /** @return list<array{id: string, words: list<string>}> the team's families, oldest first */
    public static function approved(): array
    {
        return SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->orderBy('created_at')->orderBy('id')->get(['id', 'words'])
            ->map(fn (SearchFamilyChoice $c): array => ['id' => (string) $c->id, 'words' => array_values(array_map('strval', (array) $c->words))])
            ->filter(fn (array $c): bool => count($c['words']) >= 2)->values()->all();
    }

    /** @return list<list<string>> the written families the team has not decided on, as the team's choices leave them */
    public static function waiting(): array
    {
        $placed = [];
        foreach (self::approved() as $family) {
            foreach ($family['words'] as $word) {
                $placed[HebrewSearch::normalize($word)] = true;
            }
        }
        // two words of one family the team called wrong stay apart
        $apart = [];
        foreach (SearchFamilyChoice::query()->where('status', SearchFamilyChoice::REJECTED)->pluck('words') as $i => $words) {
            foreach ((array) $words as $word) {
                $apart[HebrewSearch::normalize((string) $word)][$i] = true;
            }
        }

        $out = [];
        foreach ((array) (SearchWordFamily::query()->first(['families'])?->families ?? []) as $family) {
            $words = array_values(array_filter(array_map('strval', (array) $family), fn (string $w): bool => ! isset($placed[HebrewSearch::normalize($w)])));
            // the word most at odds with the others goes first, until none is ("משובץ" out, "משבצות", "משובצת" stay)
            while (count($words) >= 2) {
                $odds = array_map(fn (string $w): int => count(array_filter($words, fn (string $o): bool => $o !== $w
                    && array_intersect_key($apart[HebrewSearch::normalize($w)] ?? [], $apart[HebrewSearch::normalize($o)] ?? []) !== [])), $words);
                if (max($odds) === 0) {
                    break;
                }
                array_splice($words, (int) array_search(max($odds), $odds, true), 1);
            }
            if (count($words) >= 2) {
                $out[] = $words;
            }
        }

        return $out;
    }

    /** @return array<string, true> the searched words the team set aside, normalized */
    public static function hidden(): array
    {
        $out = [];
        foreach (SearchFamilyChoice::query()->where('status', SearchFamilyChoice::HIDDEN)->pluck('words') as $words) {
            foreach ((array) $words as $word) {
                $out[HebrewSearch::normalize((string) $word)] = true;
            }
        }

        return $out;
    }

    /** A family's key on the screen: its words, sorted (Postgres and SQLite order alike). */
    public static function key(array $words): string
    {
        $plain = array_map(fn ($w): string => HebrewSearch::normalize((string) $w), $words);
        sort($plain, SORT_STRING);

        return substr(sha1(implode('|', $plain)), 0, 12);
    }
}
