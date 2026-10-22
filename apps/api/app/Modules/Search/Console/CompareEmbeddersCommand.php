<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\EmbedderTrial;
use App\Modules\Retrieval\Contracts\EmbedderTrials;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   search:compare-embedders <shop>   would another embedding model find what shoppers wanted?
 *
 * The truth is the shop's own: a search and the product or page a shopper clicked from it
 * (search_clicks), the most clicked searches first. For each, the products and pages nearest it by
 * meaning, as the live search ranks them, by the index as it stands and by another model, the
 * shopper's words embedded as the live search embeds them (as a search query where the model takes one).
 *
 * The other model is the picture model's by default (retrieval.image_*), so the question answered
 * is "what if the words used it too". In memory only: nothing is written to the index or shown to
 * shoppers. Each trial is accounted on a run of retrieval.embedder_trial and stops at --max-usd.
 *
 * Then what a switch needs besides the model: each threshold that depends on it, carried to the other
 * model so it lets through as many results as today (where it falls among the similarities of every
 * query's nearest documents, in each model); and what it costs, once (the whole index embedded again)
 * and a month (the last 30 days' embedding, of changed pages and of new wordings, at the other price).
 *
 * The clicks came from what today's search showed, so they lean towards today's model: a tie
 * favours the index, and only a clear gain is one.
 */
final class CompareEmbeddersCommand extends Command
{
    /** Hebrew runs at about two characters a token, as the index estimates it. */
    private const CHARS_PER_TOKEN = 2;

    private const EXAMPLES = 12;

    /** The thresholds read against similarities of the words' model (the pictures' are the picture model's). */
    private const THRESHOLDS = [
        'search.semantic_min_similarity' => 'a product found by meaning',
        'search.meaning_only_min_similarity' => 'results by meaning alone',
        'search.content_min_similarity' => 'a guide or a page by meaning',
        'assistant.site_min_similarity' => 'what the assistant reads from the pages',
        'retrieval.min_alternative_similarity' => 'similar products (product to product: check it by eye)',
    ];

    /** The runs that embed the words day to day: changed pages, and each new wording a shopper types. */
    private const EMBEDDING_AGENTS = ['retrieval.indexer', 'retrieval.query_embedder'];

    protected $signature = 'search:compare-embedders {shop : the shop\'s slug or id}
        {--days=180 : searches clicked in this many days}
        {--queries=300 : the most clicked searches measured}
        {--limit=10 : how deep a result counts as found}
        {--provider= : the other model\'s provider (default: retrieval.image_provider)}
        {--model= : the other model (default: retrieval.image_model)}
        {--dimensions= : its vector size (default: retrieval.image_dimensions)}
        {--price= : its USD per million tokens of text (default: retrieval.image_text_usd_per_million)}
        {--max-usd=3 : each trial stops once it spent this much}';

    protected $description = 'Measure another embedding model against the index on the searches shoppers clicked from, in memory only.';

    public function handle(TenantContext $tenant, EmbedderTrials $trials): int
    {
        $key = (string) $this->argument('shop');
        $shop = $tenant->runUnscoped(fn () => Shop::query()->where('slug', $key)->orWhere('id', $key)->first());

        if ($shop === null) {
            $this->error("No shop {$key}. The shops:");
            $this->table(['slug', 'name', 'id'], $tenant->runUnscoped(fn () => Shop::query()->orderBy('name')->get(['slug', 'name', 'id'])->toArray()));

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        [$truth, $titles] = $tenant->run($shop->id, fn () => $this->truth((int) $this->option('days'), (int) $this->option('queries')));

        if ($truth === []) {
            $this->warn("{$shop->name}: no clicked searches in the last {$this->option('days')} days.");

            return self::FAILURE;
        }

        $provider = (string) ($this->option('provider') ?: Settings::get('retrieval.image_provider'));
        $model = (string) ($this->option('model') ?: Settings::get('retrieval.image_model'));
        $dimensions = (int) ($this->option('dimensions') ?: Settings::get('retrieval.image_dimensions')) ?: null;
        $price = (float) ($this->option('price') ?? Settings::get('retrieval.image_text_usd_per_million'));
        $tried = [EmbedderTrial::index(), new EmbedderTrial($model, $provider, $model, $dimensions, $price)];

        $this->info("{$shop->name}: ".count($truth).' clicked searches, measured to '.$limit.' results.');
        $results = $tenant->run($shop->id, fn () => $trials->rank($shop->id, array_keys($truth), $tried, $limit, (float) $this->option('max-usd')));
        $size = $tenant->run($shop->id, fn () => $trials->size($shop->id));

        $rows = [];
        $ranks = [];
        foreach ($results as $name => $result) {
            $ranks[$name] = [];
            foreach ($truth as $query => $items) {
                $ranks[$name][$query] = self::rank($result['ranked'][$query] ?? [], $items);
            }
            $rows[] = $result['stopped'] !== null
                ? [$name, '-', '-', '-', '-', $result['pieces'], $result['input_tokens'], '$'.number_format($result['cost_usd'], 4), 'stopped: '.$result['stopped']]
                : [$name, ...self::scores($ranks[$name], $limit), $result['pieces'], $result['input_tokens'], '$'.number_format($result['cost_usd'], 4), ''];
        }
        $this->table(['trial', 'found 1st', 'in top 5', "in top {$limit}", 'MRR', 'pieces', 'tokens', 'cost', ''], $rows);

        $this->examples($truth, $titles, $ranks, $results, $limit);

        $this->thresholds($shop->id, $results['index']['scores'] ?? [], $results[$model]['scores'] ?? [], $model);

        // What switching costs: once, the whole index and the questions of the page sections embedded again;
        // a month, what the last 30 days embedded (changed pages, new wordings), at the other price.
        $now = (float) Settings::get('retrieval.embedding_usd_per_million', $shop->id);
        $tokens = ($size['chars'] + $size['ask_chars']) / self::CHARS_PER_TOKEN;
        $month = (int) $tenant->runUnscoped(fn () => Run::query()->where('shop_id', $shop->id)->whereIn('agent', self::EMBEDDING_AGENTS)
            ->where('created_at', '>=', now()->subDays(30))->sum('input_tokens'));
        $this->newLine();
        $this->line(sprintf(
            'Once: %d pieces (%s characters) and %d question vectors (%s characters), about %s tokens: about $%s with %s.',
            $size['pieces'], number_format($size['chars']), $size['asks'], number_format($size['ask_chars']), number_format($tokens),
            number_format($tokens * $price / 1_000_000, 2), $model,
        ));
        $this->line(sprintf(
            'A month: the last 30 days embedded %s tokens (changed pages and new wordings): $%s today, about $%s with %s.',
            number_format($month), number_format($month * $now / 1_000_000, 4), number_format($month * $price / 1_000_000, 4), $model,
        ));
        $this->line('The clicks came from what today\'s search showed, so they lean towards the index: only a clear gain is one.');

        return self::SUCCESS;
    }

    /**
     * Each threshold that depends on the words' model, carried to the other one: where it falls among today's
     * similarities (the share of nearest documents it lets through), the same place among the other model's.
     *
     * @param  list<float>  $today  highest first
     * @param  list<float>  $other  highest first
     */
    private function thresholds(string $shopId, array $today, array $other, string $model): void
    {
        if ($today === [] || $other === []) {
            return;
        }

        $rows = [];
        foreach (self::THRESHOLDS as $key => $what) {
            $now = (float) Settings::get($key, $shopId);
            $share = count(array_filter($today, fn (float $s): bool => $s >= $now)) / count($today);
            $at = max(0, (int) ceil($share * count($other)) - 1);
            $rows[] = [$key, $what, number_format($now, 2), number_format(100 * $share, 1).'%', $share > 0 ? number_format($other[$at], 2) : '> '.number_format($other[0], 2)];
        }

        $this->newLine();
        $this->line('Thresholds that depend on the words\' model, carried to '.$model.' (as many results let through as today):');
        $this->table(['setting', 'for', 'today', 'lets through', $model], $rows);
    }

    /**
     * The most clicked searches and what was clicked from each (products and pages, which have vectors).
     *
     * @return array{0: array<string, array<string, int>>, 1: array<string, string>} by query: item => clicks; item => title
     */
    private function truth(int $days, int $queries): array
    {
        $rows = SearchClick::query()
            ->where('day', '>=', now()->subDays(max(1, $days))->toDateString())
            ->where(fn ($q) => $q->where('item', 'like', 'p:%')->orWhere('item', 'like', 'c:%'))
            ->selectRaw('query, item, max(title) as title, sum(clicks) as clicks')
            ->groupBy('query', 'item')
            ->get();

        $truth = [];
        $titles = [];
        foreach ($rows as $row) {
            $truth[(string) $row->query][(string) $row->item] = (int) $row->clicks;
            $titles[(string) $row->item] = (string) $row->title;
        }

        // the most clicked first; a tie by the words, so the same clicks always give the same set
        $weight = array_map('array_sum', $truth);
        $order = array_keys($truth);
        usort($order, fn (string $a, string $b): int => [$weight[$b], $a] <=> [$weight[$a], $b]);
        $order = array_slice($order, 0, max(1, $queries));

        return [array_intersect_key($truth, array_flip($order)), $titles];
    }

    /**
     * Where the first clicked item came, 1-based; null when it is not in the list.
     *
     * @param  list<string>  $ranked
     * @param  array<string, int>  $items
     */
    private static function rank(array $ranked, array $items): ?int
    {
        foreach ($ranked as $at => $item) {
            if (isset($items[$item])) {
                return $at + 1;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int|null>  $ranks
     * @return list<string> first, top 5, top limit, MRR
     */
    private static function scores(array $ranks, int $limit): array
    {
        $n = max(1, count($ranks));
        $within = fn (int $k): string => number_format(100 * count(array_filter($ranks, fn (?int $r): bool => $r !== null && $r <= $k)) / $n, 1).'%';
        $mrr = array_sum(array_map(fn (?int $r): float => $r === null ? 0.0 : 1 / $r, $ranks)) / $n;

        return [$within(1), $within(min(5, $limit)), $within($limit), number_format($mrr, 3)];
    }

    /**
     * The searches the trials disagree on most: where one finds the clicked item high and another
     * does not, with what was clicked.
     *
     * @param  array<string, array<string, int>>  $truth
     * @param  array<string, string>  $titles
     * @param  array<string, array<string, int|null>>  $ranks
     * @param  array<string, array<string, mixed>>  $results
     */
    private function examples(array $truth, array $titles, array $ranks, array $results, int $limit): void
    {
        $names = array_keys(array_filter($results, fn (array $r): bool => $r['stopped'] === null));
        if (count($names) < 2) {
            return;
        }

        $gaps = [];
        foreach (array_keys($truth) as $query) {
            $at = array_map(fn (string $name): int => $ranks[$name][$query] ?? $limit + 1, $names);
            $gaps[$query] = max($at) - min($at);
        }
        arsort($gaps);
        $gaps = array_filter(array_slice($gaps, 0, self::EXAMPLES, true));

        if ($gaps === []) {
            return;
        }

        $this->newLine();
        $this->line('Where they differ most (place of the clicked item, - = not in the top '.$limit.'):');
        $this->table(['search', 'clicked', ...$names], array_map(function (string $query) use ($truth, $titles, $ranks, $names): array {
            $clicked = $truth[$query];
            arsort($clicked);
            $item = (string) array_key_first($clicked);

            return [$query, mb_substr($titles[$item] ?? $item, 0, 50), ...array_map(fn (string $name): string => (string) ($ranks[$name][$query] ?? '-'), $names)];
        }, array_keys($gaps)));
    }
}
