<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * A provider that makes a picture from a prompt and input pictures. ImageModel picks the driver
 * for the provider a caller asks for and hands it the key saved in the panel.
 */
interface ImageDriver
{
    public function provider(): AiProviderName;

    /**
     * @param  list<array{mime: string, bytes: string}>  $images  the input pictures, in the order the prompt names them
     *
     * @throws ModelCallFailed when the provider refuses, fails or takes too long
     */
    public function generate(string $apiKey, string $model, string $prompt, array $images): ImageReply;
}
