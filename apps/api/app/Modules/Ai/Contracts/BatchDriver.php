<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One provider's batch API: many questions handed over at once, answered within a day at half the
 * price, collected later. BatchChatModel picks the driver for the provider and hands it the key.
 */
interface BatchDriver
{
    public function provider(): AiProviderName;

    /**
     * @param  list<BatchRequest>  $requests
     * @return string the provider's id for the batch
     *
     * @throws ModelCallFailed when the provider refuses the batch
     */
    public function submit(string $apiKey, string $model, array $requests): string;

    /** @throws ModelCallFailed when the provider cannot say */
    public function state(string $apiKey, string $batchId): BatchState;

    /**
     * The answers of an ended batch, one per request that was answered or refused.
     *
     * @return iterable<BatchResult>
     *
     * @throws ModelCallFailed when the results cannot be read
     */
    public function results(string $apiKey, string $batchId): iterable;
}
