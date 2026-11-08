<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * How shoppers move through the store (the Shoppers screen, the designer's redesign, 11.2026): visits, where they come
 * from, and each visit told as a short story. One module answers it (Analytics); counts and code only, no model. Plain
 * arrays, so the screen can keep them a little. No address, no raw campaign tags, no browser columns: those stay on the
 * visits log.
 */
interface ShopperVisits
{
    public const TAG = 'admin.shopper_visits';

    /** @return array{visits: int, searched: int} visits in the period, and those with a search, a photo search or a question */
    public function numbers(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /**
     * Where the visits with a search came from. source: google, facebook, instagram, whatsapp, email, direct or other.
     * searchiest: the source whose visitors search most often (enough visits to tell); campaign: the period's busiest.
     *
     * @return array{rows: list<array{source: string, n: int, pct: int}>, searchiest: string|null, campaign: array{name: string, visits: int}|null}
     */
    public function sources(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /**
     * The latest visits as short stories. plain: also the visits with no search. who: a source key, or "returning".
     * device: iphone, android, phone, tablet or computer. Each step: kind (search, photo, view, views, ask, heart, cart,
     * order, browse), text (the shopper's words or a product's title, as typed), n (how many, where it counts), picture.
     * end: order (value is money), cart (money), saved (a count), or null. id: the visit, for the visits log.
     * filter narrows them: q (words the shopper typed, or a product's title they saw, saved or added), who (as above),
     * device (phone, tablet or computer; phone holds iphone and android), ended (order, cart, saved or none).
     *
     * @param  array{q?: string, who?: string, device?: string, ended?: string}  $filter
     * @return list<array{id: int, who: string, device: string, minutes: int, at: CarbonInterface, steps: list<array{kind: string, text: string|null, n: int, picture: string|null}>, end: array{kind: string, value: string}|null}>
     */
    public function stories(Shop $shop, CarbonInterface $since, CarbonInterface $until, bool $plain, int $limit, array $filter = []): array;

    /**
     * The visit a shopper asked from: that visitor's visit begun last before the moment (or, failing it, the first one
     * after it), within a day. Null when there is none.
     */
    public function visitOf(Shop $shop, string $visitor, CarbonInterface $at): ?int;

    /**
     * How many orders in the period held each product (store ids), for "saved · bought".
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public function bought(Shop $shop, array $ids, CarbonInterface $since, CarbonInterface $until): array;
}
