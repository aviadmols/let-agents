<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Enums\AiProviderName;

/**
 * A provider and a model, as the operator chose them in the settings: `retrieval.{role}_provider`
 * and `retrieval.{role}_model`. Neither is fixed in code, so a new model is a settings change and
 * a new provider is a driver in the Ai module.
 */
final class ModelChoice
{
    public function __construct(
        public readonly ?AiProviderName $provider,
        public readonly string $providerName,
        public readonly string $model,
    ) {}

    public static function for(string $role): self
    {
        $provider = strtolower(trim((string) Settings::get("retrieval.{$role}_provider")));

        return new self(AiProviderName::tryFrom($provider), $provider, trim((string) Settings::get("retrieval.{$role}_model")));
    }

    /** How the choice reads in the panel: "openai · text-embedding-3-small". */
    public function label(): string
    {
        return $this->providerName.' · '.$this->model;
    }
}
