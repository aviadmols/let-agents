<?php

/*
 * Builds one database per flow from the same products and pictures, and a report comparing them.
 *
 *   php tools/image-caption-benchmark/build.php [--limit=30] [--flows=gemini,haiku] [--shop=https://...]
 *
 * Runs only when invoked. Spends money on the keys in ANTHROPIC_API_KEY and GEMINI_API_KEY.
 */

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Support\Drivers\AnthropicChat;
use App\Modules\Ai\Support\Drivers\GeminiEmbeddings;
use ImageCaptionBenchmark\Catalog;
use ImageCaptionBenchmark\Evaluator;
use ImageCaptionBenchmark\Flow;
use ImageCaptionBenchmark\GeminiVision;
use ImageCaptionBenchmark\MetaBuilder;
use ImageCaptionBenchmark\Report;
use ImageCaptionBenchmark\Store;
use ImageCaptionBenchmark\Vocabulary;

$config = require __DIR__.'/src/bootstrap.php';
$options = getopt('', ['limit:', 'flows:', 'shop:']);
$limit = max(1, (int) ($options['limit'] ?? $config['limit']));
$shop = rtrim((string) ($options['shop'] ?? $config['shop']), '/');
$names = isset($options['flows']) ? array_map('trim', explode(',', (string) $options['flows'])) : array_keys($config['flows']);
$embedding = $config['embedding'];

foreach ($names as $name) {
    if (! isset($config['flows'][$name])) {
        fwrite(STDERR, "Unknown flow: {$name}. Flows in config.php: ".implode(', ', array_keys($config['flows'])).PHP_EOL);
        exit(1);
    }
}

if ($embedding['provider'] !== 'gemini') {
    fwrite(STDERR, 'Only gemini embeddings are wired in this benchmark.'.PHP_EOL);
    exit(1);
}

// Every key before any call, so a run does not pay for half a comparison.
$providers = array_unique(['gemini', ...array_merge(...array_map(fn (string $n): array => array_column($config['flows'][$n], 'provider'), $names))]);
$keys = [];
foreach ($providers as $provider) {
    $value = trim((string) getenv($config['keys'][$provider]));
    if ($value === '') {
        fwrite(STDERR, 'Missing '.$config['keys'][$provider].' in the environment.'.PHP_EOL);
        exit(1);
    }
    $keys[$provider] = $value;
}

$system = (string) file_get_contents(__DIR__.'/prompt.md');
foreach (['type', 'material', 'stone', 'color', 'shape', 'style', 'pattern'] as $group) {
    $system = str_replace('{{'.$group.'}}', implode(', ', Vocabulary::values($group)), $system);
}

$run = gmdate('Ymd-His');
$dir = __DIR__.'/results/'.$run;
if (! is_dir($dir) && ! mkdir($dir, 0777, true)) {
    fwrite(STDERR, "Cannot create {$dir}".PHP_EOL);
    exit(1);
}

$drivers = ['anthropic' => new AnthropicChat, 'gemini' => new GeminiVision];
$embedder = new GeminiEmbeddings;
$flows = [];
$stores = [];
foreach ($names as $name) {
    $flows[$name] = new Flow($name, $config['flows'][$name], $drivers, $keys, $system, (int) $config['max_output_tokens']);
    $stores[$name] = new Store($dir.'/'.$name.'.sqlite', fresh: true);
}

$catalog = new Catalog($shop);
echo "Reading {$shop} ...".PHP_EOL;
$products = $catalog->products($limit);
$collections = $catalog->collections(array_map(fn (array $p): string => (string) $p['id'], $products));
echo count($products).' products'.PHP_EOL;

// One embedding call, priced and kept as a call, or null when it failed.
$embed = function (callable $request, float $price, bool $perPicture) use ($keys, $embedding): array {
    $started = hrtime(true);
    $call = ['provider' => 'gemini', 'model' => $embedding['model'], 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0, 'ms' => 0, 'error' => null];
    $vector = null;

    try {
        $result = $request($keys['gemini']);
        $vector = $result->vectors[0] ?? null;
        $call['input_tokens'] = $result->inputTokens;
        $call['cost_usd'] = $perPicture ? $price : $result->costUsd($price);
    } catch (ModelCallFailed $e) {
        $call['error'] = $e->reason.': '.mb_substr($e->getMessage(), 0, 300);
    }

    $call['ms'] = (int) round((hrtime(true) - $started) / 1e6);

    return [$vector, $call];
};

$rows = array_fill_keys($names, []);
$examples = [];
$shared = 0.0;
$skipped = 0;

foreach ($products as $at => $product) {
    $product['__shop'] = $shop;
    $id = (string) $product['id'];
    $base = MetaBuilder::fromCatalog($product, $collections[$id] ?? [], (string) $config['currency']);
    $picture = $catalog->picture($base['source']['image_url'], (int) $config['image_max_kb']);
    $label = '['.($at + 1).'/'.count($products).'] '.mb_substr($base['text']['title'], 0, 50);

    if ($picture === null) {
        echo $label.'  skipped: picture not fetched or over the size limit'.PHP_EOL;
        $skipped++;

        continue;
    }

    // What every flow shares: the picture's vector and the catalogue text's vector.
    $catalogOnly = MetaBuilder::withImage($base, null);
    [$imageVector, $imageCall] = $embed(fn (string $key) => $embedder->embedImages($key, $embedding['model'], [$picture], $embedding['dimensions']), $embedding['usd_per_picture'], true);
    [$textVector, $textCall] = $embed(fn (string $key) => $embedder->embed($key, $embedding['model'], [$catalogOnly['search']['semantic_text']], $embedding['dimensions']), $embedding['text_usd_per_million'], false);
    $shared += $imageCall['cost_usd'] + $textCall['cost_usd'];

    $user = 'Store title: '.$base['text']['title']."\nStore product type: ".$base['identity']['product_type']."\nStore tags: ".implode(', ', $base['identity']['tags'])."\nRead this product photo.";
    $line = [];
    $example = ['catalog' => $catalogOnly, 'flows' => []];

    foreach ($flows as $name => $flow) {
        $store = $stores[$name];
        $read = $flow->read($user, $picture);
        $meta = MetaBuilder::withImage($base, $read['analysis']);
        [$combinedVector, $combinedCall] = $embed(fn (string $key) => $embedder->embed($key, $embedding['model'], [$meta['search']['combined_search_text']], $embedding['dimensions']), $embedding['text_usd_per_million'], false);

        $describe = fn (?array $v): ?array => $v === null ? null : ['model' => $embedding['model'], 'dimensions' => count($v), 'stored_in' => 'vectors table'];
        $meta['vectors'] = ['text_embedding' => $describe($textVector), 'image_embedding' => $describe($imageVector), 'combined_embedding' => $describe($combinedVector)];

        $store->product($meta);
        foreach (['text' => $textVector, 'image' => $imageVector, 'combined' => $combinedVector] as $kind => $vector) {
            if ($vector !== null) {
                $store->vector($id, $kind, $embedding['model'], $vector);
            }
        }
        foreach ($read['calls'] as $call) {
            $store->call($id, $call);
        }
        $store->call($id, ['step' => 'embedding'] + $combinedCall);
        $store->call($id, ['step' => 'shared_picture'] + $imageCall);
        $store->call($id, ['step' => 'shared_text'] + $textCall);

        $row = Evaluator::product($base, $read);
        $row['reading_cost_usd'] = $row['cost_usd'];
        $row['cost_usd'] += $combinedCall['cost_usd'];
        $store->evaluation($id, $row);
        $rows[$name][] = ['product_id' => $id, 'title' => $base['text']['title']] + $row;
        $example['flows'][$name] = ['meta' => $meta, 'evaluation' => $row];

        $last = end($read['calls']);
        $line[] = $name.': '.($read['analysis'] === null ? 'FAILED '.mb_substr((string) strtok((string) $last['error'], "\n"), 0, 80) :($read['fallback'] ? 'fallback ' : 'ok ').$last['model']).' $'.number_format($row['cost_usd'], 5).' '.round($row['ms'] / 1000, 1).'s';
    }

    $examples[] = $example;
    echo $label.'  '.implode(' | ', $line).PHP_EOL;
}

$summaries = [];
foreach ($names as $name) {
    $summaries[$name] = Evaluator::summary($rows[$name], $shared);
    $stores[$name]->info('summary', $summaries[$name]);
    $stores[$name]->info('flow', $config['flows'][$name]);
    $stores[$name]->info('embedding', $embedding);
    $stores[$name]->info('shop', $shop);
    $stores[$name]->info('prompt_version', MetaBuilder::PROMPT_VERSION);
    $stores[$name]->info('built_at', gmdate('c'));
}

Report::build($dir, $shop, $config['flows'], $stores, $rows, $summaries, $examples, (int) $config['examples'], $skipped);
file_put_contents(__DIR__.'/results/latest', $run);

echo PHP_EOL;
foreach ($summaries as $name => $summary) {
    echo str_pad($name, 10).' answered '.$summary['answered_pct'].'%  fallback '.$summary['fallback_used'].'  conflicts '.$summary['conflicts'].'  total $'.number_format($summary['total_cost_usd'], 4).'  per 1000 products $'.number_format($summary['per_1000_products_usd'], 2).PHP_EOL;
}
echo PHP_EOL.'Report: '.$dir.'/report.html'.PHP_EOL;
echo 'Databases: '.implode(', ', array_map(fn (Store $s): string => $s->path, $stores)).PHP_EOL;
echo 'Query both: php tools/image-caption-benchmark/query.php "טבעת זהב לבן עד 2000"'.PHP_EOL;
