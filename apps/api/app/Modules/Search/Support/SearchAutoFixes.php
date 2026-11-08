<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\AutoFixes;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\ChooseWordFamily;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;

/**
 * What the search fixed on its own, for "Fixed by Lets": the searches that found nothing which the night resolved (to
 * products, or to a word of the same meaning) with no one deciding, and the word families its judge approved. Each
 * with what shoppers found since, and an undo that does what the operator's screens do: the resolution set aside with
 * its synonym, the family called wrong. Code only.
 */
final class SearchAutoFixes implements AutoFixes
{
    public function fixed(Shop $shop, int $limit): array
    {
        $rows = [];
        foreach (SearchResolution::query()->where('status', SearchResolution::RESOLVED)->whereNull('decided_by')->latest()->limit($limit)->get(['id', 'query', 'products', 'synonym_means', 'matched_by', 'created_at']) as $r) {
            $ids = array_map('strval', (array) $r->products);
            $rows[] = [
                'key' => 'resolution:'.$r->id,
                'from' => HebrewSearch::withFinals((string) $r->query),
                'to' => $r->synonym_means !== null ? HebrewSearch::withFinals((string) $r->synonym_means) : (string) (CatalogProduct::query()->where('external_id', $ids[0] ?? '')->value('title') ?? ''),
                'kind' => preg_match('/spell|typo|keyboard|כתיב/i', (string) $r->matched_by) === 1 ? 'typo' : 'same',
                'at' => Carbon::instance($r->created_at),
                'found' => $this->foundSince([(string) $r->query], Carbon::instance($r->created_at)),
                'picture' => CatalogProduct::query()->where('external_id', $ids[0] ?? '')->value('image_url'),
            ];
        }
        foreach (SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->where('auto', true)->latest()->limit($limit)->get(['id', 'words', 'created_at']) as $c) {
            $words = array_values(array_map('strval', (array) $c->words));
            if (count($words) < 2) {
                continue;
            }
            $rows[] = [
                'key' => 'family:'.$c->id,
                'from' => HebrewSearch::withFinals($words[1]),
                'to' => HebrewSearch::withFinals($words[0]),
                'kind' => 'same',
                'at' => Carbon::instance($c->created_at),
                'found' => $this->foundSince(array_map(fn (string $w): string => HebrewSearch::normalize($w), $words), Carbon::instance($c->created_at)),
                'picture' => null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(fn (array $r): array => ['at' => $r['at']->locale(app()->getLocale())->diffForHumans()] + $r, array_slice($rows, 0, $limit));
    }

    public function count(Shop $shop): int
    {
        return SearchResolution::query()->where('status', SearchResolution::RESOLVED)->whereNull('decided_by')->count()
            + SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->where('auto', true)->count();
    }

    public function undo(Shop $shop, string $key, ?int $userId): ?string
    {
        if (str_starts_with($key, 'resolution:')) {
            $r = SearchResolution::query()->find(substr($key, 11));
            if ($r === null) {
                return null;
            }
            $r->update(['status' => SearchResolution::REJECTED, 'decided_by' => $userId, 'decided_at' => now()]);
            // the synonym the night added goes with its resolution
            if ($r->synonym_means !== null) {
                SearchSynonym::query()->where('term', $r->query)->where('means', $r->synonym_means)->where('origin', 'resolution')->delete();
            }

            return __('search::today.auto.undone', ['words' => HebrewSearch::withFinals((string) $r->query)]);
        }
        if (str_starts_with($key, 'family:')) {
            $c = SearchFamilyChoice::query()->find(substr($key, 7));
            if ($c === null) {
                return null;
            }
            app(ChooseWordFamily::class)->handle((string) $shop->id, SearchFamilyChoice::REJECTED, array_values(array_map('strval', (array) $c->words)), (string) $c->id);

            return __('search::today.auto.undone', ['words' => implode(' · ', array_map(fn ($w): string => HebrewSearch::withFinals((string) $w), (array) $c->words))]);
        }

        return null;
    }

    /** @param list<string> $plain normalized words */
    private function foundSince(array $plain, Carbon $since): int
    {
        $t = SearchTerm::query()->whereIn('query', $plain)->where('day', '>=', $since->toDateString())
            ->selectRaw('COALESCE(SUM(searches), 0) as searches, COALESCE(SUM(empty), 0) as empty')->first();

        return max(0, (int) ($t->searches ?? 0) - (int) ($t->empty ?? 0));
    }
}
