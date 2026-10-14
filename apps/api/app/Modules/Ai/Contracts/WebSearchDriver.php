<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * A provider's model that can search the web before it answers with a JSON object. Only some
 * providers can; WebSearchModel picks the driver for the provider a caller asks for.
 */
interface WebSearchDriver
{
    public function provider(): AiProviderName;

    /** @throws ModelCallFailed when the provider refuses or the reply is not JSON */
    public function research(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, int $maxSearches): WebAnswer;
}
