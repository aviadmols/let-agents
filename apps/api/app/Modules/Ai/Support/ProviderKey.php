<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;

/** The key saved in the panel for a provider, if it is connected. */
final class ProviderKey
{
    /** @throws ModelCallFailed when no connected key exists */
    public static function for(AiProviderName $provider): string
    {
        $key = AiProvider::query()->where('provider', $provider)->where('status', ProviderStatus::Connected)->first()?->api_key;

        if ($key === null || $key === '') {
            throw new ModelCallFailed(ModelCallFailed::NO_KEY);
        }

        return $key;
    }
}
