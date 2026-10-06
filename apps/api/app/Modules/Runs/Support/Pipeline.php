<?php

namespace App\Modules\Runs\Support;

/**
 * The order things happen in, so the panel can draw it.
 *
 * Runs record what ran, not what the shape of the work is. This is that shape, written down once:
 * a few stages, each a list of actions in the order they follow one another, and for each action
 * whether a model is involved and which one. An action nobody has run yet still appears, greyed,
 * which is half the value of the screen.
 *
 * Nothing here imports a module. A run is identified by its action string, which is what the
 * modules already record, so this stays a description and never a dependency.
 */
final class Pipeline
{
    /** A step whose model is chosen in the settings, rather than fixed. */
    public const FROM_SETTING = 'setting';

    /**
     * stage => action => [model, runs_outside]
     *
     * `model` is null when no model is involved at all, a name when it is fixed, or a setting key
     * when an operator chooses it. `outside` marks work a model does away from the platform, in
     * an agent of its own, which is why it costs the platform nothing.
     *
     * @var array<string, array<string, array{model: string|null, outside?: bool}>>
     */
    public const STAGES = [
        'intake' => [
            'catalog.sync' => ['model' => null],
            'search.build_index' => ['model' => null],
            'enrichment.read_in_code' => ['model' => null],
            'enrichment.read_promises' => ['model' => null],
            'enrichment.match_article_products' => ['model' => null],
        ],
        'retrieval' => [
            'retrieval.build_index' => ['model' => 'retrieval.embedding_model'],
            'retrieval.build_image_index' => ['model' => 'retrieval.image_model'],
            'retrieval.match_products' => ['model' => 'retrieval.match_model'],
        ],
        'enrichment' => [
            'enrichment.import_vocabulary' => ['model' => null],
            'enrichment.create_task_file' => ['model' => null],
            'enrichment.import_results' => ['model' => self::FROM_SETTING, 'outside' => true],
        ],
        'compute' => [
            'enrichment.import_relation_rules' => ['model' => null],
            'enrichment.compute_rankings' => ['model' => null],
            'enrichment.compute_relations' => ['model' => null],
        ],
        'nightly' => [
            'analytics.compute_scores' => ['model' => null],
            'analytics.compute_popularity' => ['model' => null],
        ],
        'live' => [
            'assistant.answer' => ['model' => 'assistant.answer_model'],
            'retrieval.embed_queries' => ['model' => 'retrieval.embedding_model'],
        ],
        'checks' => [
            'connections.test' => ['model' => null],
            'ai.test_provider' => ['model' => self::FROM_SETTING],
        ],
    ];

    /** Every action the pipeline knows, in order. @return list<string> */
    public static function actions(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::STAGES)));
    }

    /** @return array{model: string|null, outside?: bool}|null */
    public static function step(string $action): ?array
    {
        foreach (self::STAGES as $actions) {
            if (isset($actions[$action])) {
                return $actions[$action];
            }
        }

        return null;
    }

    /** When the nightly work is due, as the schedule declares it. @return array<string, string> */
    public const CLOCK = [
        'catalog.sync' => '02:30',
        'search.build_index' => '02:40',
        'retrieval.build_index' => '02:45',
        'retrieval.build_image_index' => '02:45',
        'retrieval.match_products' => '02:45',
        'analytics.compute_scores' => '04:15',
        'analytics.compute_popularity' => '04:15',
    ];
}
