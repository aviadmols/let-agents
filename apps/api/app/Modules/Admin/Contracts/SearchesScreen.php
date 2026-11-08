<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The Searches screen (the designer's redesign, 11.2026): what shoppers look for, in their own words, and what they find.
 * One module answers it (Search); counts and code only, no model. Every list is plain arrays, so the screen can keep
 * them a little (the cache keeps no objects). Shoppers' words are data, shown as typed.
 */
interface SearchesScreen
{
    public const TAG = 'admin.searches_screen';

    /**
     * @return array{searches: int, searches_before: int, questions: int, answered: int, photos: int, photo_adds: int, found: int, asked: int}
     */
    public function kpis(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array;

    /**
     * Searches and adds to the cart from search, by ReportSeries::key() at the period's scale.
     *
     * @return array{searches: array<string, int>, adds: array<string, int>}
     */
    public function daily(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /**
     * The words searched, with what they found and what came of it. sort: searched, sales or fix (what needs one).
     *
     * @return list<array{query: string, searches: int, before: int, clicked: int|null, adds: int, sales: float, found: string|null, picture: string|null, empty: bool, fix: bool}>
     */
    public function rows(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, string $sort, int $limit): array;

    /**
     * What shoppers started looking for: the words that grew most against the period before, new ones first.
     *
     * @return list<array{query: string, searches: int, before: int, picture: string|null}>
     */
    public function rising(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, int $limit): array;

    /**
     * The questions asked most, and where the answer came from. from: page (its title in where), product, team, general, or none.
     * answer: what the shopper was told (null when nothing); first and last: when it was first and last asked (timestamps).
     *
     * @return list<array{question: string, times: int, answered: bool, from: string, where: string|null, answer: string|null, first: int, last: int}>
     */
    public function questions(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /**
     * The latest photo searches that found something: the shopper's photo, the product it found first, the first few it
     * showed (found: title and picture each), how many in all, and when (a timestamp).
     *
     * @return list<array{photo: string|null, title: string, picture: string|null, seen: string|null, found: list<array{title: string, picture: string|null}>, total: int, at: int}>
     */
    public function photos(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /**
     * The words and phrases that keep coming back in searches and questions, the most first.
     *
     * @return list<array{phrase: string, n: int}>
     */
    public function words(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /**
     * What a shopper would get for some words, as the store's search answers them (the owner trying it): how many, and
     * the first products. Counts nothing.
     *
     * @return array{total: int, products: list<array{title: string, picture: string|null, price: string|null, url: string|null}>}
     */
    public function tryIt(Shop $shop, string $words, int $limit): array;
}
