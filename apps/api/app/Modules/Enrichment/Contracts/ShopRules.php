<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * The rules a shop writes of its products in its own words, on the knowledge screen ("the book 'X' does not belong
 * with books like 'Y'; it goes with the biographies"): each is read in the background into a plan of what changes
 * (tags added or taken off, products kept apart or put together), shown to the team with the products it touches,
 * and applied only when the team approves it.
 *
 * The Taxonomy module binds it here (it requires this module, not the other way round). Call inside the shop's tenant.
 */
interface ShopRules
{
    /**
     * The shop's rules, the latest first: each with its text, its status (reading | proposed | unclear | applied |
     * rejected | failed), what was understood or asked back (note), what ties the products it names now (link), when it was written, and the plan's changes, a
     * product each: tags to add and to take off (labels), products it is kept apart from and put together with.
     *
     * @return list<array{id: string, text: string, mentions: list<string>, status: string, note: string|null, link: string|null, at: string, changes: list<array{external_id: string, title: string, image: string|null, url: string|null, add: list<string>, remove: list<string>, apart: list<string>, alike: list<string>}>}>
     */
    public function list(): array;

    /**
     * A rule written: kept, and read in the background. Null when the text is empty or too long.
     *
     * @param  list<array{type: string, id: string, label: string}>  $mentions  what it names with "@" (mentionables), kept
     *                                                                          only where "@label" is still in the text
     */
    public function add(string $text, ?int $userId, array $mentions = []): ?string;

    /**
     * What a rule may name with "@", for some words typed after it: the store's products as its own search finds them,
     * then its categories, posts and pages by their names. Empty for fewer than one letter.
     *
     * @return list<array{type: string, id: string, label: string, sub: string|null, image: string|null}>
     */
    public function mentionables(string $query, int $limit = 8): array;

    /** The team's word on a plan: applied, or rejected. Nothing changes until this. */
    public function decide(string $id, bool $approve, ?int $userId): void;

    /** A rule that failed, or came back unclear, read again as it is written. */
    public function retry(string $id): void;

    /** A rule taken off the list. What an applied rule changed stays as the team approved it. */
    public function remove(string $id): void;
}
