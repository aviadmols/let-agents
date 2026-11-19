<?php

namespace App\Modules\Assistant\Contracts;

/**
 * The store's searches an answer about products leads into: under the answer's cards, a button for each search that holds
 * what was asked, its filters already chosen, so the shopper sees every matching product and narrows them on their own.
 * "יש לכם טבעת יהלום בזהב לבן עד 3000?" is the search "טבעת · יהלום · זהב לבן · עד ₪3,000"; a ring asked to go with the
 * bracelet on the screen is the rings sharing its values ("טבעת · זהב דו-גוני · 14K", "טבעת · וינטג'"). Read in code off the
 * store's search index, the one the drawer holds, no model.
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round). Call inside the shop's
 * tenant.
 */
interface AnswerSearches
{
    /**
     * At most $max searches, each with at least $min products to show (in stock where the store keeps stock) and, where
     * $beyond, at least one the answer did not already show (its cards; a whole set shown as a grid without filters may be
     * the same products, filterable); none for a question that names nothing the store files its products by. Each:
     * `label` its values as a shopper reads them, joined (the price apart, written by the drawer in the store's currency);
     * `query` the same values as words to search; `chips` each value as the index files it (its filter's key and the value's
     * key, with the label); `price` the range the question asked, or null; `count` how many products it shows; under a
     * match asked for, `first` those of its products found to go with `anchor`, the best first, for the drawer to show first.
     *
     * @param  list<string>  $picks  the external ids of the products the answer shows, in its order
     * @param  array{anchor: string, ids: list<string>}|null  $goesWith  a product of another kind asked to go with `anchor`:
     *                                                                   the searches are of the kind of the picks, by the values they share with it;
     *                                                                   `ids` those found to go with it, the best first
     * @return list<array{label: string, query: string, chips: list<array{k: string, v: string, l: string}>, price: array{min: float|null, max: float|null}|null, count: int, first?: list<string>}>
     */
    public function searches(string $shopId, string $question, array $picks, ?array $goesWith, int $max, int $min, bool $beyond = true): array;
}
