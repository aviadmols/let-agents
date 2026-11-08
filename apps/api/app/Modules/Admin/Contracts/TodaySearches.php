<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The search's part of Today (the designer's redesign, 11.2026): how many searched and asked and how many found,
 * the words searched most with what they found, what went to the cart through search with the words that found it,
 * and the live feed of searches as they happen. One module answers it (Search); counts only, no model.
 */
interface TodaySearches
{
    public const TAG = 'admin.today_searches';

    /** @return array{searches: int, questions: int, found: int} searches and questions in the period, and how many of them found something */
    public function found(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /**
     * The words searched most, with the period before for the trend.
     *
     * @return list<array{query: string, searches: int, before: int, found: string|null, picture: string|null}>
     */
    public function top(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, int $limit): array;

    /** The words searched most on one day (the shop's best), or null. */
    public function topOn(Shop $shop, CarbonInterface $day): ?string;

    /**
     * What went to the cart from search, the most first, with the words that found it most.
     *
     * @return list<array{title: string, picture: string|null, adds: int, phrase: string|null, photo: bool}>
     */
    public function sold(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /**
     * The latest searches, photo searches and questions, newest first, narrowed by $search. Each row carries what
     * the shopper got and everything recorded about it (address, device, session, page), shown when the owner opens it.
     *
     * @return list<array{id: string, at: CarbonInterface, kind: string, query: string, got: string, picture: string|null, details: list<array{0: string, 1: string}>}>
     */
    public function feed(Shop $shop, string $search, int $limit): array;
}
