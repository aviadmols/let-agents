<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * What a product or a post was tagged with by meaning (the Taxonomy module's tag bank), each tag with
 * what it rests on: the words of the store's text, what its picture shows, or what is known of the
 * work. Shown on the scan screen beside what the scan built, so the team sees what was understood
 * and why. Empty where nothing was tagged.
 *
 * The Taxonomy module binds it here (it requires this module, not the other way round). Call inside
 * the shop's tenant.
 */
interface ProductMeaning
{
    /**
     * The kept and waiting tags first, the most central first; then those the check took out.
     *
     * @param  string  $type  product | content
     * @return list<array{group: string, label: string, weight: float, source: string, evidence: string|null, explicit: bool, status: string, note: string|null}>
     */
    public function of(string $type, string $id, string $locale): array;
}
