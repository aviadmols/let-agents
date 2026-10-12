<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fixed questions asked of one shop in full and in dynamic context, side by side.
 *
 *   assistant:context-eval rocks-and-gold --dry     what each mode would give the model, and why: no model is called
 *   assistant:context-eval rocks-and-gold           both modes answered for real, with quality and cost
 *
 * Each question is first searched the way the storefront searches it (counted=1, so the shop's
 * search report is untouched), and the products it would show are the products asked about. The
 * questions and what is expected of each are in resources/evals/{shop}.json. Answers are not saved
 * and no shopper's daily limit is used. A detailed JSON file is written to storage/app/evals.
 */
final class ContextEvalCommand extends Command
{
    protected $signature = 'assistant:context-eval {shop : slug or ID} {--file= : questions file, resources/evals/{slug}.json by default}
        {--dry : no model: what each mode would send, and why} {--base-url= : the API that runs the search, app.url by default}';

    protected $description = 'Ask fixed questions in full and in dynamic context and compare what was sent, what came back and what it cost.';

    public function handle(TenantContext $tenant, AnswerSiteQuestion $answers): int
    {
        $shop = $tenant->runUnscoped(fn () => Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->first());
        $file = (string) ($this->option('file') ?: dirname(__DIR__).'/resources/evals/'.($shop->slug ?? '').'.json');
        $questions = (array) (json_decode((string) @file_get_contents($file), true)['questions'] ?? []);
        $site = $shop === null ? null : $tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shop->id)->value('site_key'));

        if ($shop === null || $questions === [] || ! is_string($site)) {
            $this->error($shop === null ? 'Shop not found.' : ($questions === [] ? "No questions in {$file}." : 'The shop has no store connection.'));

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');
        $dry = (bool) $this->option('dry');
        $catalog = $tenant->run($shop->id, fn () => CatalogProduct::query()->whereNull('removed_at')->get(['external_id', 'sku', 'title', 'price'])
            ->mapWithKeys(fn (CatalogProduct $p): array => [(string) $p->external_id => ['sku' => (string) $p->sku, 'title' => (string) $p->title, 'price' => $p->price === null ? null : (float) $p->price]])->all());
        $skus = array_flip(array_filter(array_map(fn (array $p): string => $p['sku'], $catalog)));
        $report = ['shop' => $shop->slug, 'dry' => $dry, 'at' => now()->toIso8601String(), 'questions' => []];
        $rows = [];

        foreach ($questions as $i => $q) {
            $text = (string) $q['question'];
            $shown = $this->shown($base, $site, $text);
            $entry = ['id' => $q['id'] ?? 'q'.($i + 1), 'question' => $text, 'expect' => $q['expect'] ?? [], 'shown' => $shown, 'type' => AnswerContext::type($text), 'constraints' => AnswerContext::constraints($text)];

            if ($dry) {
                $entry['modes'] = $answers->preview($shop->id, $text, $shown);
                $full = $entry['modes'][AnswerContext::FULL];
                $dynamic = $entry['modes'][AnswerContext::DYNAMIC];
                $expected = (string) ($q['type'] ?? '');
                $rows[] = [$entry['id'], mb_substr($text, 0, 40), $entry['type'].($entry['constraints'] !== [] ? '+'.implode('+', $entry['constraints']) : '').($expected !== '' && $expected !== $entry['type'] ? ' ≠ '.$expected : ''), count($full['products']).' → '.count($dynamic['products']),
                    count($full['passages']).' → '.count($dynamic['passages']), $full['estimated_input_tokens'].' → '.$dynamic['estimated_input_tokens']];
                $this->reasons($entry['id'], $dynamic['debug']);
            } else {
                foreach ([AnswerContext::FULL, AnswerContext::DYNAMIC] as $mode) {
                    $asked = $answers->evaluate($shop->id, $text, $shown, $mode);
                    $entry['modes'][$mode] = $asked + ['failures' => $this->failures($text, $asked, (array) ($q['expect'] ?? []), $skus, $catalog)];
                    $entry['modes'][$mode]['pass'] = $entry['modes'][$mode]['failures'] === [];
                }
                $rows[] = [$entry['id'], mb_substr($text, 0, 40), $entry['type'],
                    ...array_map(fn (string $m): string => $this->verdict($entry['modes'][$m]), [AnswerContext::FULL, AnswerContext::DYNAMIC])];
            }

            $report['questions'][] = $entry;
        }

        $this->table($dry ? ['id', 'question', 'type', 'products full → dynamic', 'passages full → dynamic', 'est. input tokens full → dynamic'] : ['id', 'question', 'type', 'full', 'dynamic'], $rows);

        if (! $dry) {
            $report['summary'] = $this->summary($report['questions']);
            $this->table(['mode', 'pass', 'answered', 'avg in', 'avg out', 'avg cost', 'avg ms', 'checker changed', 'refused', 'failed'],
                array_map(fn (string $m, array $s): array => [$m, ...array_values($s)], array_keys($report['summary']), $report['summary']));
        }

        $path = storage_path('app/evals/'.$shop->slug.'-'.($dry ? 'dry-' : '').now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Details: '.$path);

        return self::SUCCESS;
    }

    /** @return list<string> the products the storefront would show for these words, best first */
    private function shown(string $base, string $site, string $question): array
    {
        $response = Http::timeout(30)->acceptJson()->get($base.'/api/v1/search/'.$site, ['q' => $question, 'counted' => 1]);
        $products = (array) $response->json('groups.product', []);

        return array_slice(array_values(array_filter(array_map(fn ($p): string => (string) ($p['external_id'] ?? ''), $products))), 0, AnswerSiteQuestion::MAX_PRODUCTS);
    }

    /** @param array{products: list<array<string, mixed>>, passages: list<array<string, mixed>>}|null $debug */
    private function reasons(string $id, ?array $debug): void
    {
        foreach ((array) ($debug['products'] ?? []) as $p) {
            $this->line(sprintf('  %s  %s #%d %s %s  sem %.2f  lex %.2f  pos %.2f  = %.2f  %s', $id, $p['kept'] ? '+' : '-', $p['position'], $p['external_id'], mb_substr((string) $p['title'], 0, 40),
                $p['semantic'], $p['lexical'], $p['position_score'], $p['final'], $p['reason']));
        }
        foreach ((array) ($debug['passages'] ?? []) as $p) {
            $this->line(sprintf('  %s  %s [%s] %s  sim %.2f  %d chars  %s', $id, $p['kept'] ? '+' : '-', $p['kind'], mb_substr((string) $p['title'], 0, 40), $p['similarity'], $p['chars'], $p['reason']));
        }
    }

    /**
     * What an answer got wrong against what the question expects; empty when it passes.
     *
     *   outcome              answered, no_info or no_match ("no_info" also accepts no_match: nothing made up),
     *                        or a refusal: out_of_scope, personal_data, support
     *   outcome_not          outcomes it must not be: a fair question on a sensitive subject is not refused
     *   products_any        SKUs or ids, one of which must be picked
     *   picks_max_price      every pick at or under this price in the catalog
     *   picks_min_price      every pick at or over it
     *   picks_title_any      every pick's title holds one of these words
     *   answer_contains_any  the answer holds one of these
     *   price_must_be        a price in the answer, if any, is this one: no price is ever made up
     *
     * @param  array{result: array<string, mixed>, trace: array<string, mixed>}  $asked
     * @param  array<string, mixed>  $expect
     * @param  array<string, string>  $skus  sku => external id
     * @param  array<string, array{sku: string, title: string, price: float|null}>  $catalog  by external id
     * @return list<string>
     */
    private function failures(string $question, array $asked, array $expect, array $skus, array $catalog): array
    {
        $failures = [];
        $outcome = (string) ($asked['result']['outcome'] ?? '');
        $answer = (string) ($asked['result']['answer'] ?? '');
        $wanted = (string) ($expect['outcome'] ?? 'any');
        $picked = array_values(array_filter(array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), (array) ($asked['result']['picks'] ?? []))));
        $empty = in_array($outcome, ['no_info', 'no_match'], true);

        if ($wanted !== 'any' && $outcome !== $wanted && ! ($wanted === 'no_info' && $empty)) {
            $failures[] = "outcome {$outcome}, expected {$wanted}";
        }

        if (in_array($outcome, (array) ($expect['outcome_not'] ?? []), true)) {
            $failures[] = "outcome {$outcome}, which it must not be";
        }

        $products = array_map(fn ($p): string => $skus[(string) $p] ?? (string) $p, (array) ($expect['products_any'] ?? []));
        if ($products !== [] && array_intersect($products, $picked) === []) {
            $failures[] = 'none of the expected products picked';
        }

        foreach ($picked as $id) {
            $product = $catalog[$id] ?? null;
            $price = $product['price'] ?? null;
            if (isset($expect['picks_max_price']) && ($price === null || $price > (float) $expect['picks_max_price'])) {
                $failures[] = "pick {$id} at {$price} is over {$expect['picks_max_price']}";
            }
            if (isset($expect['picks_min_price']) && ($price === null || $price < (float) $expect['picks_min_price'])) {
                $failures[] = "pick {$id} at {$price} is under {$expect['picks_min_price']}";
            }
            if (isset($expect['picks_title_any']) && ! array_filter((array) $expect['picks_title_any'], fn (string $w): bool => mb_stripos((string) ($product['title'] ?? ''), $w) !== false)) {
                $failures[] = "pick {$id} is not one of: ".implode('/', (array) $expect['picks_title_any']);
            }
        }

        if (isset($expect['answer_contains_any']) && ! array_filter((array) $expect['answer_contains_any'], fn (string $w): bool => mb_stripos($answer, $w) !== false)) {
            $failures[] = 'the answer does not say: '.implode(' / ', (array) $expect['answer_contains_any']);
        }

        if (isset($expect['price_must_be'])) {
            // Numbers in the answer that are not in the question (a SKU asked about) and could be a price.
            preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $answer, $m);
            foreach ($m[0] as $number) {
                $value = (float) str_replace(',', '', $number);
                if ($value >= 100 && mb_strpos($question, $number) === false && abs($value - (float) $expect['price_must_be']) > 0.01) {
                    $failures[] = "a price made up: {$number}";
                }
            }
        }

        return $failures;
    }

    /** @param array{result: array<string, mixed>, trace: array<string, mixed>, pass: bool} $asked */
    private function verdict(array $asked): string
    {
        $trace = $asked['trace'];
        $writer = (array) ($trace['writer'] ?? []);
        $check = (array) ($trace['check'] ?? []);

        return ($asked['pass'] ? 'PASS ' : 'FAIL ('.implode('; ', $asked['failures']).') ').($asked['result']['outcome'] ?? '?')
            .(($trace['from'] ?? null) === 'code' ? ' (code)' : ' '.($writer['in'] ?? 0).'/'.($writer['out'] ?? 0).' $'.number_format((float) ($writer['cost_usd'] ?? 0) + (float) ($check['cost_usd'] ?? 0), 5))
            .(isset($check['verdict']) ? ' check:'.$check['verdict'] : '').(isset($trace['failed']) ? ' FAILED:'.$trace['failed'] : '');
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, array<string, string|int>>
     */
    private function summary(array $questions): array
    {
        $summary = [];

        foreach ([AnswerContext::FULL, AnswerContext::DYNAMIC] as $mode) {
            $runs = array_map(fn (array $q): array => $q['modes'][$mode], $questions);
            $modeled = array_values(array_filter($runs, fn (array $r): bool => isset($r['trace']['writer']) && $r['trace']['writer'] !== []));
            $checked = array_filter($modeled, fn (array $r): bool => ($r['trace']['check']['ran'] ?? false) === true);
            $n = max(1, count($modeled));
            $sum = fn (callable $f): float => array_sum(array_map($f, $modeled));

            $summary[$mode] = [
                'pass' => count(array_filter($runs, fn (array $r): bool => $r['pass'])).'/'.count($runs),
                'answered' => count(array_filter($runs, fn (array $r): bool => ($r['result']['outcome'] ?? '') === 'answered')),
                'avg_in' => (int) round($sum(fn (array $r): float => (float) $r['trace']['writer']['in'] + (float) ($r['trace']['check']['in'] ?? 0)) / $n),
                'avg_out' => (int) round($sum(fn (array $r): float => (float) $r['trace']['writer']['out'] + (float) ($r['trace']['check']['out'] ?? 0)) / $n),
                'avg_cost' => '$'.number_format($sum(fn (array $r): float => (float) $r['trace']['writer']['cost_usd'] + (float) ($r['trace']['check']['cost_usd'] ?? 0)) / $n, 5),
                'avg_ms' => (int) round($sum(fn (array $r): float => (float) $r['trace']['writer']['ms'] + (float) ($r['trace']['check']['ms'] ?? 0)) / $n),
                'checker_changed' => count(array_filter($checked, fn (array $r): bool => ($r['trace']['check']['verdict'] ?? 'kept') !== 'kept')).'/'.count($checked),
                'refused' => count(array_filter($modeled, fn (array $r): bool => ($r['trace']['refused'] ?? null) !== null)),
                'failed' => count(array_filter($runs, fn (array $r): bool => isset($r['trace']['failed']))),
            ];
        }

        return $summary;
    }
}
