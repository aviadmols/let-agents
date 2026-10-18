<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * A module's part of the shop's analytics screen: its numbers for the period, and its lists (search
 * words, failed searches, questions, saved products, IP addresses), each in full for "the whole list".
 * Asked inside the shop. Counts only: no model, no cost.
 *
 * Numbers a source gives (each optional): searches, photo_searches, questions{all, answered, unanswered,
 * off}, wishlist{lists, saved, phone, shared}, ips{over, blocked}.
 */
interface ShopReport
{
    public const REPORT_TAG = 'admin.shop_report';

    /** @return array<string, mixed> */
    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /** @return list<string> the lists this source gives: words, questions, empty, noclick, wish, ips */
    public function lists(): array;

    /**
     * One list, most first (or by name), filtered by words; at most $limit rows, and how many there are.
     *
     * @return array{rows: list<ReportRow>, total: int}
     */
    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array;

    /**
     * What a row's button does (a synonym for a word, blocking an IP). Returns what to tell the
     * manager, or null when this source does not do that.
     */
    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string;
}
