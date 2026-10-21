<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * What another embedding model would find, measured before anything is switched: for each query, the
 * products and pages nearest it by each trial, ranked as the live search ranks by meaning (one entry
 * per document, its best piece). Every trial reads the same pieces: those the index holds a vector for.
 *
 * Nothing is written: not the index, not the saved query vectors. A trial other than the index embeds
 * the pieces' own texts in memory, streamed, so a large catalogue never sits in memory whole. Each
 * trial asks SpendGuard before every call and is accounted on a run of its own. Call inside the shop's
 * tenant.
 */
interface EmbedderTrials
{
    /**
     * By trial name. Its ranked list is by query: 'p:<external id>' or 'c:<external id>', nearest first.
     * Its scores are the similarities of every query's nearest documents, highest first: where a
     * threshold falls in them says how many results it lets through, so a threshold of one model can
     * be carried to another. Queries are embedded as the live search embeds them (QueryVectors::asQuery).
     * Each trial stops once it spent $maxUsd.
     *
     * @param  list<string>  $queries
     * @param  list<EmbedderTrial>  $trials
     * @return array<string, array{ranked: array<string, list<string>>, scores: list<float>, pieces: int, input_tokens: int, cost_usd: float, stopped: string|null}>
     */
    public function rank(string $shopId, array $queries, array $trials, int $limit, float $maxUsd): array;

    /**
     * What embedding the whole index again would send to a model: every piece (products, pages, purchases)
     * and the questions each page section answers, in characters.
     *
     * @return array{pieces: int, chars: int, asks: int, ask_chars: int}
     */
    public function size(string $shopId): array;
}
