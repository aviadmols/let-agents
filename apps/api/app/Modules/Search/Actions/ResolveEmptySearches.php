<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use Illuminate\Support\Facades\DB;

/**
 * Searches that found nothing, answered at night.
 *
 *   1. gather    queries that found nothing at least search.resolve_min_searches times in the last
 *                search.resolve_days days, not resolved before (or resolved as "none" and since
 *                doubled)
 *   2. find      the query's nearest products and guides by meaning, from Retrieval's index;
 *                code, with one small embedding the first time the words are seen
 *   3. match     a model picks which candidates the shopper was looking for, and whether the
 *                shopper's word is the store's word for something ("קרש" → "עץ")
 *   4. gate      code keeps only refs it offered, at most 8, and a synonym that is a word the
 *                store really uses and differs from the query
 *   5. check     a model from another family accepts or refuses each one; the same family on
 *                both sides stops the run before any call
 *   6. apply     an accepted resolution is what that search shows from the next request on,
 *                and its synonym joins the search index at the next build
 *
 * Nothing here runs while a shopper waits, and a query is matched once per set of candidates.
 */
final class ResolveEmptySearches
{
    public const AGENT = 'search.resolver';

    public const ACTION = 'search.resolve';

    public const PROMPT_VERSION = 'v1';

    private const PROMPTS = __DIR__.'/../Prompts/';

    private const MAX_MATCHES = 8;

    private const WORDS = 80;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly SemanticSearch $semantic,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->resolve($run, $shopId)),
        );
    }

    private function resolve(RunContext $run, string $shopId): void
    {
        $matcher = $this->role('resolve');
        $checker = $this->role('check');
        $stats = ['looked' => 0, 'resolved' => 0, 'none' => 0, 'refused' => 0, 'unchanged' => 0, 'stopped' => null];

        if ($matcher['provider'] === null || $checker['provider'] === null) {
            $run->fail('search::runs.resolve_no_provider');

            return;
        }

        if ($matcher['provider']->family() === $checker['provider']->family()) {
            $run->fail('search::runs.resolve_same_family', ['family' => $matcher['provider']->label()]);

            return;
        }

        $index = LoadedIndex::for($shopId);
        $words = $this->storeWords();
        $proposals = [];

        foreach ($this->queries($shopId) as $row) {
            $stats['looked']++;
            $query = HebrewSearch::normalize((string) $row->query);
            $candidates = $index === null ? [] : $this->candidates($shopId, $query, $index['records']);
            $fingerprint = hash('sha256', $query.'|'.self::PROMPT_VERSION.'|'.implode(',', array_column($candidates, 'id')));
            $existing = SearchResolution::query()->where('query_hash', hash('sha256', $query))->first();

            if ($existing !== null && $existing->fingerprint === $fingerprint) {
                $stats['unchanged']++;

                continue;
            }

            if ($candidates === []) {
                $this->save($shopId, $query, $fingerprint, (int) $row->empty, [], [], null, SearchResolution::NONE, $matcher, null, 'no_candidates');
                $stats['none']++;

                continue;
            }

            try {
                $reply = $this->ask($run, 'resolve', $matcher, $this->prompt('resolve'), [
                    'query' => $query,
                    'candidates' => array_map(fn (array $c): array => ['ref' => $c['ref'], 'kind' => $c['kind'], 'title' => $c['title'], 'words' => $c['words']], $candidates),
                    'words' => $words,
                ]);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $stats['stopped'] = $e->reason;
                break;
            }

            $proposal = $this->gate($query, $reply->data, $candidates, $index['records']);

            if ($proposal === null) {
                $this->save($shopId, $query, $fingerprint, (int) $row->empty, [], [], null, SearchResolution::NONE, $matcher, null, mb_substr(trim((string) ($reply->data['reason'] ?? '')), 0, 300) ?: 'no_match');
                $stats['none']++;

                continue;
            }

            $proposals[] = $proposal + ['fingerprint' => $fingerprint, 'searches' => (int) $row->empty, 'candidates' => $candidates];
        }

        if ($proposals !== [] && $stats['stopped'] === null) {
            try {
                $verdicts = $this->check($run, $checker, $words, $proposals);
            } catch (SpendCapReached) {
                $verdicts = null;
                $stats['stopped'] = 'spend_cap';
            } catch (ModelCallFailed $e) {
                $verdicts = null;
                $stats['stopped'] = $e->reason;
            }

            foreach ($proposals as $i => $p) {
                // No verdict is a refusal: nothing reaches the search that the second model did not accept.
                $verdict = $verdicts['r'.($i + 1)] ?? null;
                $accepted = ($verdict['verdict'] ?? null) === 'accept';
                $reason = mb_substr(trim((string) ($verdict['reason'] ?? ($verdicts === null ? 'unchecked' : 'no_verdict'))), 0, 300);

                $this->save($shopId, $p['query'], $p['fingerprint'], $p['searches'], $p['products'], $p['content'], $p['synonym'], $accepted ? SearchResolution::RESOLVED : SearchResolution::REFUSED, $matcher, $checker, $reason, $p['candidates']);
                $stats[$accepted ? 'resolved' : 'refused']++;
            }
        }

        $run->output($stats + ['matcher' => $matcher['name'].' · '.$matcher['model'], 'checker' => $checker['name'].' · '.$checker['model']])
            ->summary('search::runs.resolved', [
                'looked' => (string) $stats['looked'],
                'resolved' => (string) $stats['resolved'],
                'none' => (string) $stats['none'],
                'refused' => (string) $stats['refused'],
            ]);
    }

    /** @return iterable<object{query: string, empty: int}> */
    private function queries(string $shopId): iterable
    {
        $since = now()->subDays((int) Settings::get('search.resolve_days', $shopId) - 1)->toDateString();
        $min = (int) Settings::get('search.resolve_min_searches', $shopId);
        $limit = (int) Settings::get('search.resolve_per_run', $shopId);

        $rows = SearchTerm::query()->where('day', '>=', $since)->where('query', '!=', CountSearch::PHOTO)
            ->select('query')->selectRaw('SUM(empty) as empty, SUM(searches) as searches')
            ->groupBy('query')
            ->havingRaw('SUM(empty) >= ?', [$min])
            ->orderByDesc(DB::raw('SUM(empty)'))->orderBy('query')
            ->limit($limit * 3)
            ->get();

        $done = SearchResolution::query()->whereIn('query', $rows->pluck('query'))->get()->keyBy('query');
        $out = [];

        foreach ($rows as $row) {
            // Mostly empty: a query that sometimes finds things is not a search without an answer.
            if ((int) $row->empty < 0.8 * (int) $row->searches) {
                continue;
            }

            $existing = $done->get($row->query);

            // Resolved, refused, or taken back by the team: settled. "None" is tried again when
            // the problem has doubled, so a product the shop added later is found.
            if ($existing !== null && ($existing->status !== SearchResolution::NONE || (int) $row->empty < 2 * max(1, $existing->searches))) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return list<array{ref: string, id: string, kind: string, title: string, words: string}>
     */
    private function candidates(string $shopId, string $query, array $records): array
    {
        $out = [];
        $limit = (int) Settings::get('search.resolve_candidates');

        foreach ($this->semantic->nearText($shopId, $query, ['product', 'content'], $limit) as $hit) {
            $id = ($hit['source'] === 'product' ? 'p:' : 'c:').$hit['external_id'];
            $record = $records[$id] ?? null;

            if ($record === null || ($record['t'] === 'product' && ($record['s'] ?? 0) !== 1)) {
                continue;
            }

            $out[] = ['ref' => 'c'.(count($out) + 1), 'id' => $id, 'kind' => $record['t'] === 'product' ? 'product' : 'guide', 'title' => (string) $record['title'], 'words' => mb_substr((string) ($record['kw'] ?? ''), 0, 160)];
        }

        return $out;
    }

    /** @return list<string> */
    private function storeWords(): array
    {
        return CatalogCategory::query()->active()->where('product_count', '>', 0)->orderByDesc('product_count')->orderBy('name')->limit(self::WORDS)->pluck('name')->map(fn ($n): string => (string) $n)->all();
    }

    /**
     * Code's checks on the matcher's answer. Returns null when nothing survives.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{ref: string, id: string, kind: string, title: string, words: string}>  $candidates
     * @param  array<string, array<string, mixed>>  $records
     * @return array{query: string, products: list<string>, content: list<string>, titles: list<string>, synonym: string|null}|null
     */
    private function gate(string $query, array $data, array $candidates, array $records): ?array
    {
        $byRef = [];

        foreach ($candidates as $c) {
            $byRef[$c['ref']] = $c;
        }

        $products = [];
        $content = [];
        $titles = [];

        foreach (array_slice(array_values(array_unique(array_map('strval', (array) ($data['matches'] ?? [])))), 0, self::MAX_MATCHES) as $ref) {
            $c = $byRef[$ref] ?? null;

            if ($c === null) {
                continue;
            }

            $titles[] = $c['title'];
            $c['kind'] === 'product' ? $products[] = substr($c['id'], 2) : $content[] = substr($c['id'], 2);
        }

        $synonym = mb_substr(trim((string) ($data['synonym'] ?? '')), 0, 120);
        $synonym = $synonym === '' ? null : $synonym;
        $means = $synonym === null ? '' : HebrewSearch::normalize($synonym);

        if ($synonym !== null) {
            $used = false;

            foreach ($records as $record) {
                if (str_contains(' '.HebrewSearch::normalize($record['title'].' '.($record['kw'] ?? '')).' ', ' '.$means.' ')) {
                    $used = true;
                    break;
                }
            }

            // A word the shop never uses helps no search; the query itself is not a synonym.
            if (! $used || $means === '' || $means === $query || str_contains(' '.$query.' ', ' '.$means.' ')) {
                $synonym = null;
            }
        }

        if ($products === [] && $content === [] && $synonym === null) {
            return null;
        }

        return ['query' => $query, 'products' => $products, 'content' => $content, 'titles' => $titles, 'synonym' => $synonym];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $checker
     * @param  list<string>  $words
     * @param  list<array<string, mixed>>  $proposals
     * @return array<string, array<string, mixed>> verdicts by r-id
     */
    private function check(RunContext $run, array $checker, array $words, array $proposals): array
    {
        $reply = $this->ask($run, 'check', $checker, $this->prompt('resolve_check'), [
            'items' => array_map(fn (array $p, int $i): array => ['id' => 'r'.($i + 1), 'query' => $p['query'], 'matches' => $p['titles'], 'synonym' => $p['synonym']], $proposals, array_keys($proposals)),
            'words' => $words,
        ]);
        $verdicts = [];

        foreach ((array) ($reply->data['verdicts'] ?? []) as $verdict) {
            if (is_array($verdict) && isset($verdict['id'])) {
                $verdicts[(string) $verdict['id']] = $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * @param  list<string>  $products
     * @param  list<string>  $content
     * @param  array{provider: AiProviderName, name: string, model: string}  $matcher
     * @param  array{provider: AiProviderName, name: string, model: string}|null  $checker
     * @param  list<array<string, mixed>>  $candidates
     */
    private function save(string $shopId, string $query, string $fingerprint, int $searches, array $products, array $content, ?string $synonym, string $status, array $matcher, ?array $checker, ?string $reason, array $candidates = []): void
    {
        SearchResolution::query()->updateOrCreate(['shop_id' => $shopId, 'query_hash' => hash('sha256', $query)], [
            'query' => $query,
            'status' => $status,
            'products' => $products,
            'content' => $content,
            'synonym_means' => $synonym,
            'candidates' => array_map(fn (array $c): array => ['id' => $c['id'], 'title' => $c['title']], $candidates),
            'fingerprint' => $fingerprint,
            'searches' => $searches,
            'matched_by' => $matcher['name'].' · '.$matcher['model'],
            'checked_by' => $checker === null ? null : $checker['name'].' · '.$checker['model'],
            'reason' => $reason,
            'decided_by' => null,
            'decided_at' => null,
        ]);

        if ($status === SearchResolution::RESOLVED && $synonym !== null) {
            SearchSynonym::query()->firstOrCreate(['term' => $query, 'means' => $synonym], ['shop_id' => $shopId, 'origin' => 'resolution']);
        }
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     */
    private function ask(RunContext $run, string $name, array $role, string $system, array $input): ModelReply
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxOutput = (int) Settings::get("search.{$name}_max_output_tokens");
        $in = (float) Settings::get("search.{$name}_input_usd_per_million");
        $out = (float) Settings::get("search.{$name}_output_usd_per_million");

        // Hebrew runs at about two characters a token; estimating high is the safe side.
        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);

        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("search.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("search.{$name}_model"))];
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(self::PROMPTS.$name.'.'.self::PROMPT_VERSION.'.md');
    }
}
