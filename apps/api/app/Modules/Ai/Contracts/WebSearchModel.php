<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One request to a model that may search the web, with the key saved in the panel. Callers ask
 * SpendGuard first and record the tokens, and the searches (which cost apiece), on their run.
 */
interface WebSearchModel
{
    /** @throws ModelCallFailed when there is no connected key, the provider cannot search, refuses, or the reply is not JSON */
    public function research(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, int $maxSearches): WebAnswer;
}
