<?php

namespace ImageCaptionBenchmark;

use PDO;

/**
 * One flow's database: a SQLite file holding each product's meta object, the columns a search
 * filters on, its vectors, and every model call with what it cost. Nothing outside results/.
 */
final class Store
{
    public readonly PDO $db;

    public function __construct(public readonly string $path, bool $fresh = false)
    {
        if ($fresh && is_file($path)) {
            unlink($path);
        }

        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS info (key TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE IF NOT EXISTS products (
                id TEXT PRIMARY KEY, title TEXT, url TEXT, image_url TEXT, meta TEXT,
                product_type TEXT, price_min REAL, price_max REAL, available INTEGER, karat INTEGER,
                colors TEXT, materials TEXT, stones TEXT, shapes TEXT, styles TEXT, sizes TEXT,
                search_text TEXT
            );
            CREATE TABLE IF NOT EXISTS vectors (product_id TEXT, kind TEXT, model TEXT, vector TEXT, PRIMARY KEY (product_id, kind));
            CREATE TABLE IF NOT EXISTS calls (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id TEXT, step TEXT, provider TEXT, model TEXT, input_tokens INTEGER, output_tokens INTEGER, cost_usd REAL, ms INTEGER, error TEXT);
            CREATE TABLE IF NOT EXISTS evaluation (product_id TEXT PRIMARY KEY, data TEXT);
            SQL);
    }

    public function info(string $key, mixed $value): void
    {
        $this->db->prepare('REPLACE INTO info (key, value) VALUES (?, ?)')->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    public function read(string $key): mixed
    {
        $statement = $this->db->prepare('SELECT value FROM info WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : json_decode($value, true);
    }

    public function product(array $meta): void
    {
        $f = $meta['search']['filterable_fields'];
        $list = fn (array $values): string => $values === [] ? '' : '|'.implode('|', $values).'|';

        $this->db->prepare('REPLACE INTO products VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $meta['identity']['product_id'],
            $meta['text']['title'],
            $meta['source']['url'],
            $meta['source']['image_url'],
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $f['product_type'][0] ?? null,
            $meta['commerce']['price_min'],
            $meta['commerce']['price_max'],
            $meta['commerce']['available'] === null ? null : (int) $meta['commerce']['available'],
            $f['karat'],
            $list($f['color']),
            $list($f['material']),
            $list($f['stone']),
            $list($f['shape']),
            $list($f['style']),
            $list(array_map('strval', $f['sizes'])),
            $meta['search']['combined_search_text'],
        ]);
    }

    /** @param list<float> $vector */
    public function vector(string $productId, string $kind, string $model, array $vector): void
    {
        $this->db->prepare('REPLACE INTO vectors VALUES (?, ?, ?, ?)')->execute([$productId, $kind, $model, json_encode($vector)]);
    }

    public function call(string $productId, array $call): void
    {
        $this->db->prepare('INSERT INTO calls (product_id, step, provider, model, input_tokens, output_tokens, cost_usd, ms, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$productId, $call['step'], $call['provider'], $call['model'], $call['input_tokens'], $call['output_tokens'], $call['cost_usd'], $call['ms'], $call['error']]);
    }

    public function evaluation(string $productId, array $row): void
    {
        $this->db->prepare('REPLACE INTO evaluation VALUES (?, ?)')->execute([$productId, json_encode($row, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return list<array<string, mixed>> */
    public function products(): array
    {
        return $this->db->query('SELECT * FROM products')->fetchAll();
    }

    /** @return array<string, list<float>> product id => vector */
    public function vectors(string $kind): array
    {
        $statement = $this->db->prepare('SELECT product_id, vector FROM vectors WHERE kind = ?');
        $statement->execute([$kind]);

        return array_map(fn (string $v): array => json_decode($v, true), $statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** What each step cost, for the report. @return list<array<string, mixed>> */
    public function costs(): array
    {
        return $this->db->query('SELECT step, provider, model, COUNT(*) AS calls, SUM(error IS NOT NULL) AS failed, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, ROUND(SUM(cost_usd), 6) AS cost_usd FROM calls GROUP BY step, provider, model ORDER BY step DESC')->fetchAll();
    }
}
