<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Support\HebrewSearch;
use Illuminate\Support\Facades\Cache;

/**
 * What a store's team decides on the word families screen: a family approved, fixed or made (its words taken out of
 * the team's other families: a word in one family at most), one that is wrong, a searched word set aside. Saved, then
 * the store's search index built again on the queue, once for a run of clicks. Code only.
 */
final class ChooseWordFamily
{
    /** Clicks within this many seconds share one build of the index. */
    private const BUILD_AFTER = 20;

    /** A word of a family, at most this long. */
    private const WORD_MAX = 40;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<string>  $words
     * @param  string|null  $id  the team's family this replaces, when it is one
     * @return SearchFamilyChoice|null null when too few words are left for a family (a family of the team's emptied goes)
     */
    public function handle(string $shopId, string $status, array $words, ?string $id = null): ?SearchFamilyChoice
    {
        $words = $this->clean($shopId, $words);
        $saved = $this->tenant->run($shopId, function () use ($shopId, $status, $words, $id): ?SearchFamilyChoice {
            $choice = $id === null ? null : SearchFamilyChoice::query()->find($id);
            if ($status !== SearchFamilyChoice::HIDDEN && count($words) < 2) {
                $choice?->delete();

                return null;
            }
            if ($status === SearchFamilyChoice::APPROVED) {
                $this->takeFromOthers($words, $choice?->id);
            }
            $choice ??= new SearchFamilyChoice(['shop_id' => $shopId]);
            $choice->fill(['status' => $status, 'words' => $words, 'decided_by' => auth()->id()])->save();

            return $choice;
        });
        $this->buildSoon($shopId);

        return $saved;
    }

    /**
     * @param  list<string>  $words
     * @return list<string> as the team wrote them, trimmed, each once (by its letters), at most the store's cap
     */
    private function clean(string $shopId, array $words): array
    {
        $out = [];
        foreach ($words as $word) {
            $word = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $word)), 0, self::WORD_MAX);
            $key = HebrewSearch::normalize($word);
            if ($key !== '' && ! isset($out[$key])) {
                $out[$key] = $word;
            }
        }

        return array_slice(array_values($out), 0, max(2, (int) Settings::get('search.family_words_max', $shopId)));
    }

    /** @param list<string> $words */
    private function takeFromOthers(array $words, ?string $except): void
    {
        $keys = array_map(fn (string $w): string => HebrewSearch::normalize($w), $words);
        $others = SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->when($except !== null, fn ($q) => $q->whereKeyNot($except))->get();
        foreach ($others as $other) {
            $left = array_values(array_filter((array) $other->words, fn ($w): bool => ! in_array(HebrewSearch::normalize((string) $w), $keys, true)));
            if (count($left) === count((array) $other->words)) {
                continue;
            }
            count($left) >= 2 ? $other->update(['words' => $left]) : $other->delete();
        }
    }

    private function buildSoon(string $shopId): void
    {
        $key = 'search:families-build:'.$shopId;
        if (Cache::add($key, true, self::BUILD_AFTER * 3)) {
            dispatch(function () use ($shopId, $key): void {
                Cache::forget($key);
                app(BuildSearchIndex::class)->handle($shopId, RunTrigger::System);
            })->delay(now()->addSeconds(self::BUILD_AFTER));
        }
    }
}
