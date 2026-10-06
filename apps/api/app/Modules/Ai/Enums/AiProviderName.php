<?php

namespace App\Modules\Ai\Enums;

enum AiProviderName: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic (Claude)',
            self::Gemini => 'Google (Gemini)',
        };
    }

    /**
     * The family a model comes from. A writer and its reviewer must come from different families:
     * two models that learned the same way make the same mistakes. See docs/ADR/0008.
     */
    public function family(): string
    {
        return $this->value;
    }

    /** What this provider does in Rega. See docs/ADR/0003. */
    public function role(): string
    {
        return __("ai::providers.roles.{$this->value}");
    }
}
