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
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchPageTags;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;

/**
 * The tags a page offers: "חומרים לבניית דק", "ברגים לדק", each a search the shopper may want next.
 *
 *   1. gather    pages from the search index whose words changed since their tags were written,
 *                or that never had any; at most search.tags_per_run a night
 *   2. write     one model writes tags for a batch of pages: a label and the search behind it
 *   3. gate      code keeps a tag only when the store's own search finds enough for it, besides
 *                the page itself, and drops repeats and labels that are the page's own title
 *   4. check     a model from another family sees each tag with what it would show and accepts
 *                or refuses; the same family on both sides stops the run before any call
 *   5. keep      the accepted tags, best first; the page bank searches them when it is built,
 *                so what a tag shows follows stock. Labels the team took off stay off.
 *
 * A page is written once per set of words: nothing is paid for twice.
 */
final class WritePageTags
{
    public const AGENT = 'search.tagger';

    public const ACTION = 'search.write_tags';

    public const PROMPT_VERSION = 'v1';

    private const PROMPTS = __DIR__.'/../Prompts/';

    private const WORDS = 80;

    private const SHOWN = 4;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly SearchCatalog $search,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->write($run, $shopId)),
        );
    }

    private function write(RunContext $run, string $shopId): void
    {
        $writer = $this->role('tags');
        $checker = $this->role('check');
        $stats = ['pages' => 0, 'tags' => 0, 'refused' => 0, 'dropped' => 0, 'stopped' => null];

        if ($writer['provider'] === null || $checker['provider'] === null) {
            $run->fail('search::runs.tags_no_provider');

            return;
        }

        if ($writer['provider']->family() === $checker['provider']->family()) {
            $run->fail('search::runs.tags_same_family', ['family' => $writer['provider']->label()]);

            return;
        }

        $index = LoadedIndex::for($shopId);

        if ($index === null) {
            $run->fail('search::runs.tags_no_index');

            return;
        }

        $words = CatalogCategory::query()->active()->where('product_count', '>', 0)->orderByDesc('product_count')->orderBy('name')->limit(self::WORDS)->pluck('name')->map(fn ($n): string => (string) $n)->all();
        $batchSize = (int) Settings::get('search.tags_batch');

        foreach (array_chunk($this->pages($shopId, $index['records']), max(1, $batchSize)) as $batch) {
            try {
                $written = $this->writeBatch($run, $shopId, $writer, $words, $batch, $stats);
                $this->checkAndKeep($run, $writer, $checker, $batch, $written, $stats);
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $stats['stopped'] = $e->reason;
                break;
            }
        }

        $run->output($stats + ['writer' => $writer['name'].' · '.$writer['model'], 'checker' => $checker['name'].' · '.$checker['model']])
            ->summary('search::runs.tagged', ['pages' => (string) $stats['pages'], 'tags' => (string) $stats['tags'], 'refused' => (string) $stats['refused']]);
    }

    /**
     * Pages whose tags are missing or were written for other words, products before guides.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<array{ref: string, type: string, id: string, external_id: string, title: string, words: string, fingerprint: string}>
     */
    private function pages(string $shopId, array $records): array
    {
        $done = SearchPageTags::query()->get(['page_type', 'external_id', 'fingerprint'])
            ->mapWithKeys(fn (SearchPageTags $t): array => [$t->page_type.':'.$t->external_id => $t->fingerprint])->all();
        $limit = (int) Settings::get('search.tags_per_run', $shopId);
        $out = [];

        foreach (['product', 'content'] as $type) {
            foreach ($records as $id => $record) {
                if (($record['t'] ?? null) !== $type) {
                    continue;
                }

                $externalId = substr((string) $id, 2);
                $title = (string) $record['title'];
                $kw = mb_substr((string) ($record['kw'] ?? ''), 0, 160);
                $fingerprint = hash('sha256', self::PROMPT_VERSION.'|'.$title.'|'.$kw);

                if (($done[$type.':'.$externalId] ?? null) === $fingerprint) {
                    continue;
                }

                $out[] = ['ref' => '', 'type' => $type, 'id' => (string) $id, 'external_id' => $externalId, 'title' => $title, 'words' => $kw, 'fingerprint' => $fingerprint];

                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * One call for a batch of pages, then code's gate: a tag stays only when the store's search
     * finds enough for it besides the page itself.
     *
     * @param  array{provider: AiProviderName, name: string, model: string}  $writer
     * @param  list<string>  $words
     * @param  list<array<string, string>>  $batch
     * @param  array<string, mixed>  $stats
     * @return array<int, list<array{label: string, query: string, shows: list<string>}>> by position in the batch
     */
    private function writeBatch(RunContext $run, string $shopId, array $writer, array $words, array $batch, array &$stats): array
    {
        $perPage = (int) Settings::get('search.tags_per_page', $shopId);
        $minResults = (int) Settings::get('search.tags_min_results');
        $reply = $this->ask($run, 'tags', $writer, $this->prompt('tags'), [
            'pages' => array_map(fn (array $p, int $i): array => ['ref' => 'g'.($i + 1), 'kind' => $p['type'] === 'product' ? 'product' : 'guide', 'title' => $p['title'], 'words' => $p['words']], $batch, array_keys($batch)),
            'words' => $words,
            'per_page' => $perPage,
        ]);

        $byRef = [];

        foreach ((array) ($reply->data['pages'] ?? []) as $page) {
            if (is_array($page) && isset($page['ref'])) {
                $byRef[(string) $page['ref']] = (array) ($page['tags'] ?? []);
            }
        }

        $written = [];

        foreach ($batch as $i => $page) {
            $kept = [];
            $seen = [HebrewSearch::normalize($page['title']) => true];

            foreach (array_slice($byRef['g'.($i + 1)] ?? [], 0, $perPage + 2) as $tag) {
                $label = mb_substr(trim((string) ($tag['label'] ?? '')), 0, 40);
                $query = mb_substr(trim((string) ($tag['query'] ?? '')), 0, 60);
                $key = HebrewSearch::normalize($label);

                if (mb_strlen($label) < 3 || mb_strlen($query) < 2 || isset($seen[$key]) || count($kept) >= $perPage) {
                    $stats['dropped']++;

                    continue;
                }

                $found = $this->search->handle($shopId, $query, ['product', 'content'], count: false, perGroup: self::SHOWN + 1);
                $shows = [];

                foreach (['product', 'content'] as $group) {
                    foreach ($found['groups'][$group] as $result) {
                        if ($result['id'] !== $page['id'] && count($shows) < self::SHOWN) {
                            $shows[] = (string) $result['title'];
                        }
                    }
                }

                $others = $found['counts']['product'] + $found['counts']['content'] - (in_array($page['id'], array_column([...$found['groups']['product'], ...$found['groups']['content']], 'id'), true) ? 1 : 0);

                if ($others < $minResults) {
                    $stats['dropped']++;

                    continue;
                }

                $seen[$key] = true;
                $kept[] = ['label' => $label, 'query' => $query, 'shows' => $shows];
            }

            $written[$i] = $kept;
        }

        return $written;
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $writer
     * @param  array{provider: AiProviderName, name: string, model: string}  $checker
     * @param  list<array<string, string>>  $batch
     * @param  array<int, list<array{label: string, query: string, shows: list<string>}>>  $written
     * @param  array<string, mixed>  $stats
     */
    private function checkAndKeep(RunContext $run, array $writer, array $checker, array $batch, array $written, array &$stats): void
    {
        $items = [];
        $where = [];

        foreach ($written as $i => $tags) {
            foreach ($tags as $j => $tag) {
                $id = 't'.(count($items) + 1);
                $items[] = ['id' => $id, 'page' => $batch[$i]['title'], 'label' => $tag['label'], 'shows' => $tag['shows']];
                $where[$id] = [$i, $j];
            }
        }

        $verdicts = [];

        if ($items !== []) {
            $reply = $this->ask($run, 'check', $checker, $this->prompt('tags_check'), ['items' => $items]);

            foreach ((array) ($reply->data['verdicts'] ?? []) as $verdict) {
                if (is_array($verdict) && isset($verdict['id'])) {
                    $verdicts[(string) $verdict['id']] = $verdict;
                }
            }
        }

        $accepted = array_fill_keys(array_keys($batch), []);

        foreach ($where as $id => [$i, $j]) {
            // No verdict is a refusal: nothing reaches a page that the second model did not accept.
            if (($verdicts[$id]['verdict'] ?? null) === 'accept') {
                $tag = $written[$i][$j];
                $accepted[$i][] = ['label' => $tag['label'], 'query' => $tag['query']];
                $stats['tags']++;
            } else {
                $stats['refused']++;
            }
        }

        foreach ($batch as $i => $page) {
            $existing = SearchPageTags::query()->where('page_type', $page['type'])->where('external_id', $page['external_id'])->first();
            SearchPageTags::query()->updateOrCreate(['page_type' => $page['type'], 'external_id' => $page['external_id']], [
                'shop_id' => $this->tenant->require(),
                'title' => mb_substr($page['title'], 0, 300),
                'tags' => $accepted[$i],
                'hidden_labels' => $existing?->hidden_labels ?? [],
                'fingerprint' => $page['fingerprint'],
                'written_by' => $writer['name'].' · '.$writer['model'],
                'checked_by' => $checker['name'].' · '.$checker['model'],
            ]);
            $stats['pages']++;
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
