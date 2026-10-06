<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;

/**
 * Hands a chat request to the driver of the provider asked for, with that provider's key. The
 * drivers are the ones AiServiceProvider registers; a provider without one is refused as
 * unsupported, the same way as before there were drivers.
 */
final class ProviderChatModel implements ChatModel
{
    /** @param iterable<ChatDriver> $drivers */
    public function __construct(private readonly iterable $drivers) {}

    public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver->json(ProviderKey::for($provider), $model, $system, $user, $maxOutputTokens, $reasoningEffort);
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
