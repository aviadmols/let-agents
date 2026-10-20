<?php

namespace App\Modules\Assistant\Contracts;

/**
 * A question that asks to see a whole set of the store's products ("תציג לי את ספריית שיבולת",
 * "תן לי את כל ספרי החטופים"), answered with every product in it rather than three picks. A group
 * the store keeps (a category, a brand) is read by code; a set only meaning holds is chosen from
 * the products code found by a model and checked by one of the other family.
 *
 * The Thinking module reads it and binds it here (it requires this module, not the other way round).
 * Call inside the shop's tenant.
 */
interface ProductSets
{
    /**
     * Null when the question asks for no set, or there was nothing to look through. No ids: the store
     * was looked through and has none of it, nor anything close; the answer says so. ids are external ids, best first;
     * from is "code" (a group the store keeps), "model" (chosen now) or "bank" (chosen before); near:
     * none fit, and these are the closest the store has, the answer says so; ask: one question to
     * narrow a large set by, with its options; search: the words to search the store with for more.
     *
     * @param  callable(): bool  $mayAsk  whether a model may be asked now (the visitor's and the shop's daily questions)
     * @return array{answer: string, ids: list<string>, title: string, from: string, near: bool, page: array{title: string, url: string}|null, ask: array{question: string, options: list<string>}|null, search: string}|null
     */
    public function find(string $shopId, string $question, string $locale, callable $mayAsk): ?array;
}
