<?php

namespace App\Modules\Search\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * search:word-audit — the words each store's shoppers searched, as the search reads them: the catalogue words it takes
 * for the same word (closeWords), and those it should not, a word that shares no root with what was typed ("מל", how
 * "מ"ל" is written, for "שמלה": every cream of 70 מ"ל was a dress, Take Me Home 17.11). And the products found only
 * through such a word. Code only, no model; a report per shop in storage/app/audits, for the fixes the search needs.
 *
 *   search:word-audit --shop=take-me-home-collection
 *   search:word-audit --top=500
 */
final class WordAuditCommand extends Command
{
    protected $signature = 'search:word-audit {--shop= : one shop, by slug or ID} {--top=300 : how many of its most searched words}';

    protected $description = 'List the words the search takes for others they share no root with, in every shop, and what they bring.';

    public function handle(TenantContext $tenant): int
    {
        $shops = Shop::query()->where('status', ShopStatus::Active)
            ->when($this->option('shop') !== null, fn ($q) => $q->where(fn ($w) => $w->where('id', (string) $this->option('shop'))->orWhere('slug', (string) $this->option('shop'))))
            ->orderBy('slug')->get();
        $all = [];

        foreach ($shops as $shop) {
            $report = $tenant->run((string) $shop->id, fn (): ?array => $this->audit((string) $shop->id, max(1, (int) $this->option('top'))));
            if ($report === null) {
                $this->line("{$shop->slug}: no index");

                continue;
            }
            Storage::put('audits/word-families-'.$shop->slug.'.json', (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->line(sprintf('%s: %d words read, %d taken for an unrelated word, %d products found only so', $shop->slug, $report['words'], count($report['unrelated']), $report['products_only_so']));
            foreach (array_slice($report['unrelated'], 0, 8) as $row) {
                $this->line(sprintf('   "%s" → "%s" (%.2f), %d products: %s', $row['typed'], $row['taken_for'], $row['score'], count($row['products']), implode(' | ', array_slice($row['products'], 0, 3))));
            }
            $all[$shop->slug] = count($report['unrelated']);
        }

        $this->line('total: '.array_sum($all).' in '.count(array_filter($all)).' shops');

        return self::SUCCESS;
    }

    /** @return array{words: int, unrelated: list<array{typed: string, taken_for: string, score: float, searches: int, products: list<string>}>, products_only_so: int}|null */
    private function audit(string $shopId, int $top): ?array
    {
        $index = LoadedIndex::for($shopId);
        if ($index === null) {
            return null;
        }
        $engine = $index['engine'];
        $words = [];
        foreach (SearchTerm::query()->orderByDesc('searches')->limit($top)->get(['term', 'searches']) as $term) {
            foreach (explode(' ', HebrewSearch::normalize((string) $term->term)) as $word) {
                if (mb_strlen($word) >= 2) {
                    $words[$word] = ($words[$word] ?? 0) + (int) $term->searches;
                }
            }
        }

        $unrelated = [];
        $only = [];
        foreach ($words as $word => $searches) {
            foreach (HebrewSearch::closeWords($engine, $word) as $candidate => $score) {
                $candidate = (string) $candidate;
                if ($candidate === $word || self::related($word, $candidate)) {
                    continue;
                }
                $products = [];
                foreach ($engine['items'] as $record) {
                    $hay = ' '.$record['hay'].' ';
                    if (str_contains($hay, ' '.$candidate.' ') && ! self::holdsForm($hay, $word)) {
                        $products[] = (string) $record['title'];
                        $only[(string) $record['id']] = true;
                    }
                }
                $unrelated[] = ['typed' => $word, 'taken_for' => $candidate, 'score' => round((float) $score, 3), 'searches' => $searches, 'products' => array_slice($products, 0, 20)];
            }
        }
        usort($unrelated, fn (array $a, array $b): int => [count($b['products']) * $b['searches'], $b['score']] <=> [count($a['products']) * $a['searches'], $a['score']]);

        return ['words' => count($words), 'unrelated' => $unrelated, 'products_only_so' => count($only)];
    }

    /**
     * Whether two words share a root as Hebrew and English write them: three letters in a row in common (after their prefix
     * letters and endings), the same stem, or a number and its word.
     */
    public static function related(string $typed, string $candidate): bool
    {
        if (HebrewSearch::stem($typed) === HebrewSearch::stem($candidate) && mb_strlen($candidate) >= 3) {
            return true;
        }
        if (preg_match('/\d/u', $typed.$candidate) === 1) {
            return preg_replace('/\D+/u', '', $typed) === preg_replace('/\D+/u', '', $candidate);
        }
        $a = HebrewSearch::stem($typed);
        $b = HebrewSearch::stem($candidate);
        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (mb_strlen($short) < 3) {
            return false;
        }
        for ($i = 0; $i + 3 <= mb_strlen($short); $i++) {
            if (mb_strpos($long, mb_substr($short, $i, 3)) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Whether a product's words hold the word typed or a word it starts, as the store writes it (with a prefix letter too). */
    private static function holdsForm(string $hay, string $word): bool
    {
        return preg_match('/ [והבלמשכ]{0,2}'.preg_quote($word, '/').'/u', $hay) === 1;
    }
}
