<?php

namespace App\Modules\Enrichment\Prompts;

use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use RuntimeException;

/**
 * The instructions each task sends, kept as versioned Markdown files next to this class.
 *
 * Changing a prompt means adding a new file (".v2.md") and raising the version here, never
 * editing a released one: batches record the version and the exact text they sent.
 */
final class PromptLibrary
{
    /** @var array<string, int> task => current version */
    public const CURRENT = [
        'product_extraction' => 3,
        'fact_review' => 4,
        'content_mapping' => 2,
        'product_highlights' => 2,
        'product_summary' => 1,
    ];

    public static function version(TaskType $task): int
    {
        return self::CURRENT[$task->value];
    }

    public static function template(TaskType $task, ?int $version = null): string
    {
        $version ??= self::version($task);
        $path = __DIR__."/{$task->value}.v{$version}.md";

        if (! is_file($path)) {
            throw new RuntimeException("Prompt {$task->value} v{$version} does not exist.");
        }

        return (string) file_get_contents($path);
    }

    /**
     * The instructions with the vocabulary and the job list filled in, exactly as a model receives them.
     *
     * @param  list<array{key: string, label: array<string, string>}>  $uses  jobs from every active vocabulary
     */
    public static function render(TaskType $task, ?VocabularyDefinition $vocabulary, array $uses = []): string
    {
        $text = self::template($task);
        $useLines = implode("\n", array_map(fn (array $use): string => '- `'.$use['key'].'`: '.$use['label']['he'], $uses));

        return trim(str_replace(
            ['{{vocabulary}}', '{{uses}}'],
            [$vocabulary === null ? '' : VocabularyText::render($vocabulary), $useLines === '' ? '(none yet)' : $useLines],
            $text,
        ))."\n";
    }

    /**
     * Every job named in the shop's active vocabularies, once per key, in a fixed order.
     *
     * @param  iterable<VocabularyDefinition>  $vocabularies
     * @return list<array{key: string, label: array<string, string>}>
     */
    public static function uses(iterable $vocabularies): array
    {
        $uses = [];

        foreach ($vocabularies as $vocabulary) {
            foreach ($vocabulary->uses() as $use) {
                $uses[$use['key']] ??= ['key' => $use['key'], 'label' => $use['label']];
            }
        }

        ksort($uses);

        return array_values($uses);
    }

    public static function hash(string $renderedPrompt): string
    {
        return hash('sha256', $renderedPrompt);
    }
}
