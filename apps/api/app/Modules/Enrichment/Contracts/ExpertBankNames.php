<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * What a store's expert bank already sorts of its category names. A store's categories mix kinds ("Kosher Travel",
 * "Family Experiences", and a country each); the bank sorts the same words into attributes of their own (a destination,
 * a theme, a meal), so a category whose name a value of the bank says is offered twice when it stays a category too.
 *
 * Bound by this module, read by the search index for its category filter. Call inside the shop's tenant.
 */
interface ExpertBankNames
{
    /**
     * Of the given category names, the ids of those a type or a value of the store's active expert-bank vocabulary says:
     * the whole name in the bank's words, as code reads them (whole words, the letters Hebrew joins before a word and the
     * usual endings allowed, upper and lower case alike), or its label. Null when no active vocabulary of the store came
     * from an expert bank: then nothing changes.
     *
     * @param  array<string, string>  $names  by category id
     * @return list<string>|null
     */
    public function saidByExpertBank(array $names): ?array;
}
