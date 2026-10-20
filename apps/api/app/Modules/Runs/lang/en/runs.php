<?php

return [
    'singular' => 'Activity',
    'plural' => 'Agent activity',
    'statuses' => [
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
    ],
    'triggers' => [
        'manual' => 'Manual',
        'schedule' => 'Scheduled',
        'webhook' => 'Store update',
        'system' => 'Pipeline step',
    ],
    'fields' => [
        'sent' => 'What the agent was sent',
        'started_at' => 'Started',
        'finished_at' => 'Finished',
        'status' => 'Status',
        'agent' => 'Agent',
        'action' => 'Action',
        'shop' => 'Shop',
        'system' => 'System',
        'summary' => 'Result',
        'duration' => 'Duration',
        'tokens' => 'Tokens (in / out)',
        'tokens_detail' => 'In :input, out :output, from cache :cached',
        'cost' => 'Cost',
        'trigger' => 'Started by',
        'user' => 'User',
        'error' => 'Error',
        'provider' => 'Provider',
        'model' => 'Model',
        'input' => 'Input',
        'output' => 'Output',
    ],
    'sections' => [
        'calls' => 'What the agent was sent and returned',
        'overview' => 'Details',
        'result' => 'Result',
        'usage' => 'Model usage',
        'data' => 'Input and output',
    ],
    'duration' => [
        'ms' => ':value ms',
        'seconds' => ':value s',
    ],
    'summaries' => [
        'done' => 'Done.',
        'unexpected_error' => 'The action failed with an unexpected error. See the error field.',
    ],
    'empty' => [
        'heading' => 'No activity yet',
        'description' => 'Every agent action, such as a connection check or a model call, shows up here live.',
    ],
    'calls_help' => 'Each model call of the run: what it was sent (for a search box answer: the question, the passages the vector search found, and the products), what came back, its instructions, tokens and time.',
];
