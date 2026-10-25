<?php

namespace App\Modules\Assistant\Contracts;

/**
 * A question asked the whole way a shopper's goes, for the search check screen (the Search module), with what
 * code saw on the way and nothing of it kept: no saved answer, no logged ask, no visitor's questions used. Its
 * model calls are paid for and accounted like any other.
 */
interface TriesQuestions
{
    /**
     * @param  list<string>  $products  external ids of the products the search showed, best first
     * @return array<string, mixed> outcome (answered, no_match, no_info, out_of_scope, …), answer, from (code, bank,
     *                              model, set, none), picks, sources, not_found; and trial: run_id, writer_model,
     *                              checker_model, verdict, reason, failure, cost_usd, duration_ms, trace and looks
     *                              (each look's pages and products given to the writer)
     */
    public function trial(string $shopId, string $question, array $products, string $locale): array;
}
