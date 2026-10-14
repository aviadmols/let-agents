<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchDriver;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\CallJournal;

/**
 * Hands a batch to the driver of the provider asked for, with that provider's key, the way
 * ProviderChatModel does for one call. Each answer collected is written to the call journal as
 * the call it was, with what the caller says was sent, so a batched question reads in the
 * pipeline like any other.
 */
final class ProviderBatchModel implements BatchChatModel
{
    /** @param iterable<BatchDriver> $drivers */
    public function __construct(
        private readonly iterable $drivers,
        private readonly ?CallJournal $journal = null,
    ) {}

    public function supports(AiProviderName $provider): bool
    {
        return $this->driver($provider) !== null;
    }

    public function submit(AiProviderName $provider, string $model, array $requests): string
    {
        return $this->require($provider)->submit(ProviderKey::for($provider), $model, $requests);
    }

    public function state(AiProviderName $provider, string $batchId): BatchState
    {
        return $this->require($provider)->state(ProviderKey::for($provider), $batchId);
    }

    public function results(AiProviderName $provider, string $model, string $batchId, ?string $system = null, array $sent = []): iterable
    {
        foreach ($this->require($provider)->results(ProviderKey::for($provider), $batchId) as $result) {
            $this->journal?->record(CallJournal::CHAT, $provider->value, $model, $system, $sent[$result->customId] ?? $result->customId,
                $result->reply?->data, $result->error, $result->reply?->inputTokens ?? 0, $result->reply?->outputTokens ?? 0, 0);

            yield $result;
        }
    }

    private function driver(AiProviderName $provider): ?BatchDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver;
            }
        }

        return null;
    }

    private function require(AiProviderName $provider): BatchDriver
    {
        return $this->driver($provider) ?? throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
