<?php

namespace App\Modules\Ai\Contracts;

use App\Core\Facades\Settings;
use App\Modules\Ai\Enums\AiProviderName;

/**
 * Which provider and model one role of an agent calls, read from that agent's own settings, so
 * the system operator can change either from the agents screen without touching code.
 */
final class AgentModel
{
    public function __construct(
        public readonly AiProviderName $provider,
        public readonly string $model,
    ) {}

    /** An unknown provider value falls back to OpenAI, the default of every writer. */
    public static function from(string $providerSetting, string $modelSetting): self
    {
        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get($providerSetting)))) ?? AiProviderName::OpenAi;

        return new self($provider, trim((string) Settings::get($modelSetting)));
    }

    public static function provider(string $providerSetting): AiProviderName
    {
        return AiProviderName::tryFrom(strtolower(trim((string) Settings::get($providerSetting)))) ?? AiProviderName::OpenAi;
    }
}
