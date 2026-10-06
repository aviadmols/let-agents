<?php

return [
    'singular' => 'AI provider',
    'plural' => 'AI keys',
    'roles' => [
        'openai' => 'Writes all visitor-facing text and answers in chat.',
        'anthropic' => 'Analyzes: attribute extraction, classification, review and planning.',
        'gemini' => 'Vectors for product pictures, to find products that look alike and search pictures by words.',
    ],
    'statuses' => [
        'untested' => 'Not tested',
        'connected' => 'Working',
        'failed' => 'Failed',
    ],
    'sections' => [
        'key' => 'API key',
        'key_help' => 'Stored encrypted and shared by every shop. The check lists the available models and costs no tokens.',
        'status' => 'Status',
        'models' => 'Available models',
        'models_help' => 'The list as the provider returns it for this key, newest first. Models for each role are chosen from here.',
    ],
    'fields' => [
        'provider' => 'Provider',
        'api_key' => 'API key',
        'api_key_keep' => 'Leave empty to keep the current key.',
        'status' => 'Status',
        'key_hint' => 'Key',
        'last_checked_at' => 'Last check',
        'never' => 'Never checked',
        'last_error' => 'Last error',
        'model_count' => 'Models',
    ],
    'errors' => [
        'invalid_key' => 'Invalid key',
        'permission_denied' => 'No permission',
        'rate_limited' => 'Rate limited',
        'provider_error' => 'Provider error',
        'unreachable' => 'Unreachable',
    ],
    'actions' => [
        'test' => 'Check key',
    ],
    'notifications' => [
        'connected' => 'The key works',
        'failed' => 'Key check failed',
        'view_run' => 'View activity',
    ],
    'empty' => [
        'heading' => 'No keys yet',
        'description' => 'Add an OpenAI key for writing and an Anthropic key for analysis.',
    ],
];
