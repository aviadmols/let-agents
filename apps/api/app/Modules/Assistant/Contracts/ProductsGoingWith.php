<?php

namespace App\Modules\Assistant\Contracts;

/**
 * The products of another kind that go with the product on the shopper's screen, as the store's own search index knows
 * them: "יש טבעת שמתאימה לצמיד?" on a two-tone 14K chain-link bracelet is the store's rings, those sharing what the bracelet
 * is (its metal, colour, karat, stone, style, collection, the words of its motif) first. Read in code, no model.
 *
 * The kind is what the store's index files products as (its type filter, else its one-word categories), read from the
 * question's own words, and it must be another kind than the viewed product's: a question about the product itself
 * ("what size is it?", "is the bracelet 14K?") names none, and is left as it was (null).
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round). Call inside the shop's
 * tenant.
 */
interface ProductsGoingWith
{
    /**
     * Null when the question names no other kind and asks for no complement. Else `kind`: the kind asked, as the store
     * names it (the kinds the store's goes-with map pairs with the product's, for "what else do I need with this?"; '' when
     * it knows none); `ids`: in stock, of that kind, for the viewed product's audience, what they share best first, at most
     * $limit, empty when the store has none in stock; `shares`: by id, the values and words each shares with the viewed
     * product, as the store writes them; `complement`: whether what was asked is what is used with the product (its bits,
     * its screws, a belt for the dress) rather than a match by look.
     *
     * @param  string  $viewed  the external id of the product on the screen
     * @param  array{relations?: bool, map?: bool, look?: bool}  $sources  which sources the store lets it choose from: the
     *                                                                     store's own pairings, its goes-with map, a match by look; each on when not given
     * @return array{kind: string, ids: list<string>, shares: array<string, list<string>>, complement: bool}|null
     */
    public function goingWith(string $shopId, string $viewed, string $question, int $limit, array $sources = []): ?array;
}
