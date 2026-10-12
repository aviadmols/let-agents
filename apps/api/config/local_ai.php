<?php

/*
 * A model running on a machine of our own (Ollama, or any server with the OpenAI chat API), for
 * the writer benchmark only: assistant:bench-local. It is not a provider of the platform, nothing
 * in production calls it, and it is off unless LOCAL_AI_ENABLED is true.
 */
return [
    'enabled' => (bool) env('LOCAL_AI_ENABLED', false),

    'url' => (string) env('LOCAL_AI_URL', 'http://localhost:11434'),

    // No default model: the benchmark names the model it measures.
    'model' => (string) env('LOCAL_AI_MODEL', ''),

    // "ollama" (/api/chat, a JSON schema as the format) or "openai" (/v1/chat/completions).
    'api' => (string) env('LOCAL_AI_API', 'ollama'),

    // A model on a laptop's processor takes minutes for one answer.
    'timeout' => (int) env('LOCAL_AI_TIMEOUT', 900),

    // Qwen3 and other reasoning models think before they answer unless told not to.
    'think' => (bool) env('LOCAL_AI_THINK', false),

    // A hosted server for the same test (OpenRouter, open-weight models only): its key, never in code.
    'key' => (string) env('LOCAL_AI_KEY', ''),

    // Extra fields for an OpenAI-compatible server, as JSON: for OpenRouter
    // {"reasoning":{"enabled":false},"provider":{"require_parameters":true},"usage":{"include":true}}
    'body' => (array) json_decode((string) env('LOCAL_AI_BODY', '{}'), true),

    // Context window to ask for; the writer's input with its passages is a few thousand tokens.
    'context' => (int) env('LOCAL_AI_CONTEXT', 16384),
];
