<?php

/*
 * One query against every flow's database from a build, answers side by side.
 *
 *   php tools/image-caption-benchmark/query.php "טבעת זהב לבן עד 2000" [--image=photo.jpg] [--top=8] [--run=20261011-120000]
 *
 * The query is embedded once (GEMINI_API_KEY) and the same vectors search every database. Without
 * the key the search is by spelling and filters alone, and says so.
 */

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Support\Drivers\GeminiEmbeddings;
use ImageCaptionBenchmark\Report;
use ImageCaptionBenchmark\Search;
use ImageCaptionBenchmark\Store;

$config = require __DIR__.'/src/bootstrap.php';
$options = [];
$words = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(image|top|run)=(.*)$/s', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        $words[] = $arg;
    }
}
$raw = trim(implode(' ', $words));
$top = max(1, (int) ($options['top'] ?? 8));
$run = (string) ($options['run'] ?? trim((string) @file_get_contents(__DIR__.'/results/latest')));
$dir = __DIR__.'/results/'.$run;
$paths = $run === '' ? [] : (glob($dir.'/*.sqlite') ?: []);

if ($paths === []) {
    fwrite(STDERR, 'No databases found. Run build.php first, or pass --run=<folder in results/>.'.PHP_EOL);
    exit(1);
}

$photo = null;
if (isset($options['image'])) {
    $file = (string) $options['image'];
    $data = is_file($file) ? (string) file_get_contents($file) : '';
    $mime = $data === '' ? '' : (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        fwrite(STDERR, "Not a picture: {$file}".PHP_EOL);
        exit(1);
    }
    $photo = ['mime' => $mime, 'data' => $data];
}

if ($raw === '' && $photo === null) {
    fwrite(STDERR, 'Usage: php query.php "words" [--image=photo.jpg] [--top=8] [--run=folder]'.PHP_EOL);
    exit(1);
}

$parsed = Search::parse($raw);
$embedding = $config['embedding'];
$key = trim((string) getenv($config['keys']['gemini']));
$vectors = [];
$cost = 0.0;
$note = null;

if ($key === '') {
    $note = 'GEMINI_API_KEY is not set: spelling and filters only, no meaning or picture search.';
} else {
    $embedder = new GeminiEmbeddings;
    try {
        if (trim($parsed['words']) !== '') {
            // One space for words and pictures: the same query vector is compared with both.
            $text = $embedder->embedTextsForImages($key, $embedding['model'], [$parsed['words']], $embedding['dimensions']);
            $vectors['text'] = $vectors['text_for_pictures'] = $text->vectors[0] ?? null;
            $cost += $text->costUsd($embedding['text_usd_per_million']);
        }
        if ($photo !== null) {
            $vectors['photo'] = $embedder->embedImages($key, $embedding['model'], [$photo], $embedding['dimensions'])->vectors[0] ?? null;
            $cost += $embedding['usd_per_picture'];
        }
    } catch (ModelCallFailed $e) {
        $vectors = [];
        $note = 'Embedding the query failed ('.$e->getMessage().'): spelling and filters only.';
    }
}

$answers = [];
foreach ($paths as $path) {
    $store = new Store($path);
    $answers[basename($path, '.sqlite')] = Search::run($store, $parsed, $vectors, $top) + ['summary' => $store->read('summary'), 'flow' => $store->read('flow')];
}

echo 'Query: '.$raw.($photo !== null ? '  + photo '.$options['image'] : '').PHP_EOL;
echo 'Read as: words "'.$parsed['words'].'"'.($parsed['min'] !== null ? ' from '.$parsed['min'] : '').($parsed['max'] !== null ? ' up to '.$parsed['max'] : '')
    .($parsed['filters'] !== [] ? '  filters '.json_encode($parsed['filters'], JSON_UNESCAPED_UNICODE) : '').($parsed['size'] !== null ? '  size '.$parsed['size'] : '').PHP_EOL;
if ($note !== null) {
    echo $note.PHP_EOL;
}
echo 'Query embedding cost: $'.number_format($cost, 6).PHP_EOL;

foreach ($answers as $name => $answer) {
    echo PHP_EOL.'== '.$name.' == '.$answer['candidates'].' passed the filters'.($answer['relaxed'] ? ' (none matched every filter: filters relaxed, price kept)' : '').PHP_EOL;
    foreach ($answer['results'] as $i => $r) {
        echo str_pad((string) ($i + 1), 3).number_format($r['score'], 3).'  '.mb_substr($r['title'], 0, 60).'  ₪'.$r['price'].PHP_EOL;
    }
}

$file = Report::query($dir, $raw, $options['image'] ?? null, $parsed, $answers, $note, $cost);
echo PHP_EOL.'Side by side: '.$file.PHP_EOL;
