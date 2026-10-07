<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use Generator;

/**
 * The file format for running a batch with any model outside Let Agents (JSON Lines).
 *
 * Download:  {"type":"header", "system": "...", ...}
 *            {"type":"request", "custom_id": "px-30254-1a2b3c4d5e", "input": {...}}
 *
 * Upload:    {"type":"header", "batch_id": "...", "model": "claude-haiku-4-5"}   (optional)
 *            {"custom_id": "px-30254-1a2b3c4d5e", "output": {...}}
 *
 * An Anthropic Message Batches results file is accepted as is: the answer is read from
 * result.message.content[0].text.
 */
final class TaskFile
{
    public const FORMAT = 'let-agents-tasks/1';

    /** @return Generator<int, string> one JSON line at a time, without loading every item */
    public static function lines(EnrichmentBatch $batch): Generator
    {
        yield self::json([
            'type' => 'header',
            'format' => self::FORMAT,
            'batch_id' => $batch->id,
            'task' => $batch->task->value,
            'review_tier' => $batch->review_tier,
            'prompt' => ['key' => $batch->prompt_key, 'version' => $batch->prompt_version, 'hash' => $batch->prompt_hash],
            'suggested_model' => $batch->task->suggestedModel($batch->review_tier),
            'count' => $batch->request_count,
            'answer' => 'For each request, one line: {"custom_id": "<same>", "output": <the JSON object the instructions ask for>}',
            'system' => $batch->system_prompt,
        ]);

        // In the order the requests were made. lazyById pages by ID, so no other ordering here.
        foreach ($batch->items()->lazyById(200) as $item) {
            /** @var EnrichmentBatchItem $item */
            yield self::json(['type' => 'request', 'custom_id' => $item->custom_id, 'input' => $item->request]);
        }
    }

    /**
     * @return array{header: array<string, mixed>|null, results: array<string, array<string, mixed>>, problems: list<string>}
     */
    public static function parseResults(string $contents): array
    {
        $header = null;
        $results = [];
        $problems = [];

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row)) {
                $problems[] = 'line_'.($number + 1).':not_json';

                continue;
            }

            if (($row['type'] ?? null) === 'header') {
                $header = $row;

                continue;
            }

            $customId = $row['custom_id'] ?? null;

            if (! is_string($customId) || $customId === '') {
                $problems[] = 'line_'.($number + 1).':no_custom_id';

                continue;
            }

            $output = self::output($row);

            if ($output === null) {
                $problems[] = "{$customId}:no_output";

                continue;
            }

            $results[$customId] = $output;
        }

        return ['header' => $header, 'results' => $results, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function output(array $row): ?array
    {
        $output = $row['output'] ?? data_get($row, 'result.message.content.0.text');

        if (is_array($output)) {
            return $output;
        }

        if (! is_string($output)) {
            return null;
        }

        // Models sometimes wrap JSON in a code fence.
        $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($output)));
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
