<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Contracts\TriesQuestions;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Mail\ScanCheckMail;
use App\Modules\Search\Support\DescribedProducts;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\Transliterate;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * After a scan, the store's search tried as its shoppers will try it, and what fails mended for this store alone.
 *
 *   1. code      searches made from the store's own products, each with the products code knows it must bring: its
 *                kinds, a kind for whom (לגברים) and in a colour, a product's name, a Latin name typed in Hebrew. Free.
 *   2. shoppers  what shoppers type that no title says ("לישון", "כיס בצד"), with the products that answer each, and a
 *                few questions for the assistant: one small model, once a catalog (the same products are not asked
 *                again)
 *   3. try       every search through the store's own search, as a shopper's, nothing counted. Free.
 *   4. mend      only what failed, only for this store: a product whose own description says what was searched is
 *                tagged with it (code); the rest go to the resolver (ResolveEmptySearches), one model finds what the
 *                search should show and a model of the other family checks it. In "dry" mode (search.scan_check_mode)
 *                nothing is written, the report says what would have been.
 *   5. again     the index built again, what failed tried again.
 *   6. ask       a few questions through the assistant's whole way, as a shopper's (TriesQuestions), nothing kept.
 *   7. report    the team gets what was tried, what failed, what was mended and what it cost, by email.
 *
 * Held to search.scan_check_usd for the whole check; every call asks SpendGuard first and is on a run.
 */
final class CheckStoreSearch
{
    public const AGENT = 'search.scan_checker';

    public const ACTION = 'search.scan_check';

    public const PROMPT_VERSION = 'v1';

    /** A search passes when a product it must bring is among this many results. */
    private const TOP = 12;

    /** Products the shoppers' model reads, spread over the catalog. */
    private const SAMPLE = 80;

    /** Of what code makes: kinds, kinds for whom or in a colour, names, Latin names. */
    private const KINDS = 6;

    private const PAIRED = 3;

    private const NAMES = 6;

    private const LATIN = 4;

    /** A feature tag only where its words tell products apart: said of at most this share of the catalog. */
    private const FEATURE_MAX_SHARE = 0.3;

    /** The tag a scan check writes on a product whose description says what a shopper searched. */
    public const FEATURE_KEY = 'scan_feature';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly SearchCatalog $search,
        private readonly ResolveEmptySearches $resolver,
        private readonly BuildSearchIndex $indexer,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        $report = null;
        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: function (RunContext $run) use ($shopId, $trigger, &$report): void {
                $report = $this->tenant->run($shopId, fn (): ?array => $this->check($run, $shopId, $trigger));
            },
        );

        if ($report !== null) {
            $this->send($shopId, $report, $run);
        }

        return $run;
    }

    /** @return array<string, mixed>|null the report, null when nothing was tried */
    private function check(RunContext $run, string $shopId, RunTrigger $trigger): ?array
    {
        if (! Features::enabled('search.scan_check', $shopId)) {
            $run->output(['skipped' => 'off'])->summary('search::runs.scan_check_off');

            return null;
        }

        $index = LoadedIndex::for($shopId);
        if ($index === null) {
            $run->output(['skipped' => 'no_index'])->summary('search::runs.scan_check_no_index');

            return null;
        }

        $cap = (float) Settings::get('search.scan_check_usd', $shopId);
        $apply = Settings::get('search.scan_check_mode', $shopId) === 'apply';
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
        $spent = 0.0;

        // 1-2: what to try
        $cases = $this->fromCode($index, $locale);
        $asked = ['searches' => [], 'questions' => []];
        if ($cap > 0) {
            [$asked, $cost] = $this->fromShoppers($run, $shopId, $index, $locale);
            $spent += $cost;
            $cases = [...$cases, ...$asked['searches']];
        }

        // 3: try
        foreach ($cases as $i => $case) {
            $cases[$i] += $this->attempt($shopId, $case);
        }
        $failed = array_values(array_filter($cases, fn (array $c): bool => ! $c['passed']));

        // 4: mend what failed, this store's only
        $tags = $this->featureTags($shopId, (string) $index['hash'], (array) $index['records'], array_filter($failed, fn (array $c): bool => $c['source'] === 'shopper'), $apply);
        $resolved = [];
        $toResolve = array_values(array_unique(array_map(fn (array $c): string => $c['q'], array_filter($failed, fn (array $c): bool => $c['source'] === 'shopper'))));
        if ($toResolve !== [] && $spent < $cap) {
            $resolving = $this->resolver->handle($shopId, $trigger, $toResolve, ! $apply);
            $spent += (float) ($resolving->cost_usd ?? 0);
            $resolved = (array) (((array) $resolving->output)['items'] ?? []);
        }
        $wrote = $apply && ($tags !== [] || array_filter($resolved, fn (array $r): bool => ($r['outcome'] ?? '') === 'resolved') !== []);

        // 5: again, on the index as it is now
        if ($wrote) {
            $this->indexer->handle($shopId, $trigger);
            LoadedIndex::forget();
            foreach ($cases as $i => $case) {
                if (! $case['passed']) {
                    $after = $this->attempt($shopId, $case);
                    $cases[$i]['after'] = $after['passed'];
                    $cases[$i]['after_total'] = $after['total'];
                }
            }
        }

        // 6: a few questions the whole way a shopper's goes
        $questions = [];
        foreach (array_slice($asked['questions'], 0, (int) Settings::get('search.scan_check_questions', $shopId)) as $question) {
            if ($spent >= $cap || ! app()->bound(TriesQuestions::class)) {
                break;
            }
            try {
                $tried = app(TriesQuestions::class)->trial($shopId, $question['q'], [], $locale);
            } catch (SpendCapReached) {
                break;
            }
            $spent += (float) ($tried['trial']['cost_usd'] ?? 0);
            $picked = array_column((array) ($tried['picks'] ?? []), 'external_id');
            $questions[] = ['q' => $question['q'], 'outcome' => (string) ($tried['outcome'] ?? ''), 'answer' => mb_substr((string) ($tried['answer'] ?? ''), 0, 240),
                'picks' => array_slice(array_column((array) ($tried['picks'] ?? []), 'title'), 0, 3),
                'hit' => $question['expect'] === [] ? null : array_intersect($question['expect'], array_map(fn ($id): string => 'p:'.$id, $picked)) !== []];
        }

        $report = [
            'mode' => $apply ? 'apply' : 'dry',
            'tried' => count($cases),
            'failed' => count($failed),
            'cases' => array_map(fn (array $c): array => array_intersect_key($c, array_flip(['q', 'source', 'why', 'passed', 'total', 'after', 'after_total'])), $cases),
            'tags' => $tags,
            'resolved' => $resolved,
            'questions' => $questions,
            'cost_usd' => round($spent, 4),
            'cap_usd' => $cap,
        ];
        $run->output(array_diff_key($report, array_flip(['cases'])) + ['failures' => array_values(array_map(fn (array $c): string => $c['q'], $failed))])
            ->summary('search::runs.scan_checked', ['tried' => (string) count($cases), 'failed' => (string) count($failed), 'mended' => (string) (count($tags) + count(array_filter($resolved, fn (array $r): bool => ($r['outcome'] ?? '') === 'resolved')))]);

        return $report;
    }

    /**
     * Searches made in code from the store's own products, each with the products it must bring.
     *
     * @param  array<string, mixed>  $index
     * @return list<array{q: string, expect: list<string>, source: string, why: string}>
     */
    private function fromCode(array $index, string $locale): array
    {
        $products = array_filter($index['records'], fn (array $r, string $id): bool => str_starts_with($id, 'p:') && ($r['s'] ?? 0) === 1, ARRAY_FILTER_USE_BOTH);
        if ($products === []) {
            return [];
        }
        $cases = [];

        // its kinds: the words most titles open with (not most of the store), as the store writes them
        $byHead = [];
        $said = [];
        foreach ($products as $id => $r) {
            $head = HebrewSearch::head((string) $r['title']);
            if ($head !== '') {
                $byHead[$head][] = $id;
                $said[$head] ??= explode(' ', trim((string) $r['title']), 2)[0];
            }
        }
        $byHead = array_filter($byHead, fn (array $ids): bool => count($ids) >= 3 && count($ids) <= count($products) * 0.6);
        uasort($byHead, fn (array $a, array $b): int => count($b) <=> count($a));
        $kinds = array_slice($byHead, 0, self::KINDS, true);
        foreach ($kinds as $head => $ids) {
            $cases[] = ['q' => $said[$head], 'expect' => $ids, 'source' => 'code', 'why' => 'kind'];
        }

        // a kind for whom, and in a colour, where the store filters by them
        $facets = (array) ($index['facets'] ?? []);
        foreach (array_slice($kinds, 0, self::PAIRED, true) as $head => $ids) {
            foreach ($facets as $at => $facet) {
                $colour = preg_match('/colou?r|צבע/iu', (string) ($facet['k'] ?? '').' '.(string) ($facet['l'] ?? '')) === 1;
                if (($facet['k'] ?? '') !== 'audience' && ! $colour) {
                    continue;
                }
                // the value most of this kind carry
                $count = [];
                foreach ($ids as $id) {
                    foreach ((array) ($products[$id]['f'] ?? []) as $code) {
                        if (intdiv((int) $code, 1000) === $at) {
                            $count[$code] = ($count[$code] ?? 0) + 1;
                        }
                    }
                }
                arsort($count);
                $code = array_key_first($count);
                if ($code === null) {
                    continue;
                }
                $label = (string) ($facet['v'][$code % 1000]['l'] ?? '');
                $q = $colour ? $said[$head].' '.$label : $said[$head].($locale === 'he' ? ' ל'.$label : ' for '.$label);
                $cases[] = ['q' => $q, 'expect' => array_values(array_filter($ids, fn (string $id): bool => in_array($code, (array) ($products[$id]['f'] ?? []), true))), 'source' => 'code', 'why' => $colour ? 'colour' : 'audience'];
            }
        }

        // a product's name, as the store gives it; and a Latin name as a shopper types it in Hebrew
        $ids = array_keys($products);
        $step = max(1, intdiv(count($ids), self::NAMES));
        for ($at = 0; $at < count($ids) && count(array_filter($cases, fn (array $c): bool => $c['why'] === 'name')) < self::NAMES; $at += $step) {
            $name = trim((string) preg_split('/\s[-–—|:]\s/u', (string) $products[$ids[$at]]['title'])[0]);
            $name = implode(' ', array_slice(explode(' ', $name), 0, 5));
            if (mb_strlen($name) >= 3) {
                $cases[] = ['q' => $name, 'expect' => [$ids[$at]], 'source' => 'code', 'why' => 'name'];
            }
        }
        $latin = 0;
        foreach ($products as $id => $r) {
            if ($latin >= self::LATIN || $locale !== 'he') {
                break;
            }
            $spelled = Transliterate::hebrew((string) $r['title']);
            preg_match('/[A-Za-z]{4,}/', (string) $r['title'], $word);
            if ($spelled === [] || $word === []) {
                continue;
            }
            $same = array_keys(array_filter($products, fn (array $p): bool => stripos((string) $p['title'], $word[0]) !== false));
            if (count($same) > 8) {
                continue;
            }
            $cases[] = ['q' => Transliterate::hebrew($word[0])[0] ?? $spelled[0], 'expect' => $same, 'source' => 'code', 'why' => 'latin'];
            $latin++;
        }

        return $cases;
    }

    /**
     * What shoppers type that no title says, and a few questions, from one small model; once a catalog.
     *
     * @param  array<string, mixed>  $index
     * @return array{0: array{searches: list<array<string, mixed>>, questions: list<array<string, mixed>>}, 1: float}
     */
    private function fromShoppers(RunContext $run, string $shopId, array $index, string $locale): array
    {
        $none = [['searches' => [], 'questions' => []], 0.0];
        $products = array_filter($index['records'], fn (array $r, string $id): bool => str_starts_with($id, 'p:') && ($r['s'] ?? 0) === 1, ARRAY_FILTER_USE_BOTH);
        if ($products === []) {
            return $none;
        }
        $ids = array_keys($products);
        $step = max(1, intdiv(count($ids), self::SAMPLE));
        $sample = [];
        for ($at = 0; $at < count($ids) && count($sample) < self::SAMPLE; $at += $step) {
            $sample['p'.(count($sample) + 1)] = $ids[$at];
        }
        $about = fn (string $id): string => mb_substr(trim((string) ($products[$id]['kw'] ?? '')), 0, 90);
        $input = [
            'store' => (string) Shop::query()->whereKey($shopId)->value('name'),
            'language' => $locale,
            'searches' => (int) Settings::get('search.scan_check_searches', $shopId),
            'questions' => (int) Settings::get('search.scan_check_questions', $shopId),
            'products' => array_map(fn (string $ref, string $id): array => ['ref' => $ref, 'title' => (string) $products[$id]['title'], 'about' => $about($id)], array_keys($sample), $sample),
        ];
        $system = (string) file_get_contents(__DIR__.'/../Prompts/scan_queries.'.self::PROMPT_VERSION.'.md');
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // never asked twice for the same products, prompt and wish (by the products themselves: the tags a check adds
        // to their words are no new catalog)
        $key = 'search:scan-queries:'.$shopId.':'.hash('sha256', self::PROMPT_VERSION.'|'.json_encode([$input['language'], $input['searches'], $input['questions'],
            array_map(fn (string $id): string => $id.'|'.$products[$id]['title'], array_values($sample))], JSON_UNESCAPED_UNICODE));
        $data = Cache::get($key);
        $cost = 0.0;

        if (! is_array($data)) {
            $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('search.scan_check_provider'))));
            $model = trim((string) Settings::get('search.scan_check_model'));
            if ($provider === null || $model === '') {
                return $none;
            }
            $maxOutput = (int) Settings::get('search.scan_check_max_output_tokens');
            $in = (float) Settings::get('search.scan_check_input_usd_per_million');
            $out = (float) Settings::get('search.scan_check_output_usd_per_million');
            try {
                $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
                $reply = $this->chat->json($provider, $model, $system, $user, $maxOutput);
            } catch (SpendCapReached|ModelCallFailed $e) {
                Log::info('search: the scan check wrote no shoppers\' searches', ['shop' => $shopId, 'error' => $e->getMessage()]);

                return $none;
            }
            $cost = $reply->costUsd($in, $out);
            $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
            $data = (array) $reply->data;
            Cache::put($key, $data, now()->addDays(30));
        }

        // code's gate: only refs it gave, short lines, no link or contact
        $read = function (array $items, int $max) use ($sample): array {
            $out = [];
            foreach (array_slice($items, 0, $max) as $item) {
                $q = trim((string) preg_replace('/\s+/u', ' ', (string) ($item['q'] ?? '')));
                if ($q === '' || mb_strlen($q) > 80 || preg_match('~https?://|@|\d{6,}~', $q) === 1) {
                    continue;
                }
                $expect = array_values(array_filter(array_map(fn ($ref): ?string => $sample[(string) $ref] ?? null, (array) ($item['refs'] ?? []))));
                $out[] = ['q' => $q, 'expect' => $expect, 'source' => 'shopper', 'why' => 'shopper'];
            }

            return $out;
        };

        return [[
            'searches' => array_values(array_filter($read((array) ($data['searches'] ?? []), (int) Settings::get('search.scan_check_searches', $shopId)), fn (array $c): bool => $c['expect'] !== [])),
            'questions' => $read((array) ($data['questions'] ?? []), (int) Settings::get('search.scan_check_questions', $shopId)),
        ], $cost];
    }

    /**
     * A search as a shopper's, nothing counted: whether it brings a product it must, among the first TOP.
     *
     * @param  array{q: string, expect: list<string>}  $case
     * @return array{passed: bool, total: int}
     */
    private function attempt(string $shopId, array $case): array
    {
        $result = $this->search->handle($shopId, $case['q'], ['product'], false, self::TOP);
        $shown = array_column((array) ($result['groups']['product'] ?? []), 'id');

        return ['passed' => $case['expect'] === [] ? $shown !== [] : array_intersect($case['expect'], $shown) !== [], 'total' => (int) ($result['counts']['product'] ?? count($shown))];
    }

    /**
     * What a failed search asked that the store's own descriptions say ("כיס בצד"): those products tagged with it, so the
     * search finds them by it. Only words that tell products apart (never most of the catalog), only this store.
     *
     * @param  array<int, array{q: string}>  $failed
     * @return list<array{tag: string, products: int}>
     */
    /** @param array<string, array<string, mixed>> $records the index's, by id */
    private function featureTags(string $shopId, string $hash, array $records, array $failed, bool $apply): array
    {
        $total = CatalogProduct::query()->active()->count();
        $out = [];

        foreach ($failed as $case) {
            $words = array_values(array_filter(explode(' ', HebrewSearch::normalize($case['q'])), fn (string $w): bool => ! HebrewSearch::isFiller($w) && $w !== 'עמ' && mb_strlen($w) >= 2));
            // the longest piece of its words some description says, two words or more ("כיס בצד"), or one long word
            for ($size = min(3, count($words)); $size >= 1; $size--) {
                for ($at = 0; $at + $size <= count($words); $at++) {
                    $phrase = implode(' ', array_slice($words, $at, $size));
                    if ($size === 1 && mb_strlen($phrase) < 4) {
                        continue;
                    }
                    // only where the description says it and the product's own words do not: what its title or tags say
                    // the search finds already ("סניקרס", "14 אינץ'" in the titles), and is no feature to add
                    $ids = array_values(array_filter(DescribedProducts::find($shopId, $hash, $phrase),
                        fn (string $id): bool => isset($records[$id]) && ! HebrewSearch::says((string) $records[$id]['title'].' '.(string) ($records[$id]['kw'] ?? ''), $phrase)));
                    if ($ids === [] || count($ids) > $total * self::FEATURE_MAX_SHARE) {
                        continue;
                    }
                    $label = HebrewSearch::withFinals($phrase);
                    if ($apply) {
                        foreach (CatalogProduct::query()->whereIn('external_id', array_map(fn (string $id): string => substr($id, 2), $ids))->pluck('id') as $productId) {
                            EnrichmentFact::query()->firstOrCreate(['product_id' => $productId, 'kind' => FactKind::Tag, 'key' => self::FEATURE_KEY, 'value_text' => $label], [
                                'shop_id' => $shopId, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => hash('sha256', 'scan_check|'.$label),
                                'quote' => mb_substr($case['q'], 0, 200),
                            ]);
                        }
                    }
                    $out[$label] = ['tag' => $label, 'products' => count($ids)];
                    break 2;
                }
            }
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $report */
    private function send(string $shopId, array $report, Run $run): void
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string) Settings::get('search.scan_check_report_to')))));
        if ($to === []) {
            $to = User::query()->where('is_operator', true)->pluck('email')->filter()->unique()->values()->all();
        }
        if ($to === []) {
            return;
        }
        $shop = Shop::query()->find($shopId);

        try {
            Mail::to($to)->locale((string) config('app.locale'))->send(new ScanCheckMail((string) ($shop?->name ?: $shop?->domain), (string) $shop?->domain, $report, $run->id));
        } catch (Throwable $e) {
            Log::warning('search: the scan check report could not be sent', ['shop' => $shopId, 'error' => $e->getMessage()]);
        }
    }
}
