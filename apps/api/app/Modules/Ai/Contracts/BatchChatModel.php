<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * Many requests to a model at once, through the provider's batch API: handed over now, answered
 * within a day, at half the price of asking one by one. For everything that is not urgent (rule 9).
 *
 * Callers ask SpendGuard for the whole batch before submitting, keep the batch id, ask for its
 * state later, and record each reply's tokens on their run with the batch flag, at DISCOUNT.
 */
interface BatchChatModel
{
    /** What a batched request costs against the ordinary price. */
    public const DISCOUNT = 0.5;

    public function supports(AiProviderName $provider): bool;

    /**
     * @param  list<BatchRequest>  $requests
     * @return string the provider's id for the batch
     *
     * @throws ModelCallFailed when there is no connected key or the provider refuses
     */
    public function submit(AiProviderName $provider, string $model, array $requests): string;

    /** @throws ModelCallFailed */
    public function state(AiProviderName $provider, string $batchId): BatchState;

    /**
     * @param  array<string, string>  $sent  what each request said, by custom id, for the call journal
     * @return iterable<BatchResult>
     *
     * @throws ModelCallFailed
     */
    public function results(AiProviderName $provider, string $model, string $batchId, ?string $system = null, array $sent = []): iterable;
}
