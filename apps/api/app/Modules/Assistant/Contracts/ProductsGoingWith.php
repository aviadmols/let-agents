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
     * names it ('' for "what else do I need with this?", where the candidates are of whatever other kinds share something
     * with it); `ids`: in stock, of that kind (or of other kinds than the product's), for the viewed product's audience,
     * what they share best first, at most $limit, empty when the store has none; `shares`: by id, the values and words each
     * shares with the viewed product, as the store writes them; `complement`: whether what was asked is what is used with
     * the product (its bits, its screws, a belt for the dress) rather than a match by look.
     *
     * @param  string  $viewed  the external id of the product on the screen
     * @param  array{relations?: bool, look?: bool}  $sources  which sources the store lets it choose from: the store's own
     *                                                         pairings, a match by what products share; each on when not given
     * @return array{kind: string, ids: list<string>, shares: array<string, list<string>>, complement: bool}|null
     */
    public function goingWith(string $shopId, string $viewed, string $question, int $limit, array $sources = []): ?array;

    /**
     * Whether the question names a kind of product the store files, other than the viewed product's own, in any words and
     * any language the store's index knows: "איזה שרשראות יכולים להיות באותו הסגנון?" on a ring's page does, "is it 14K?"
     * does not. Read off the index alone, nothing scored: for routing a question to goingWith().
     */
    public function asksAnotherKind(string $shopId, string $viewed, string $question): bool;
}
