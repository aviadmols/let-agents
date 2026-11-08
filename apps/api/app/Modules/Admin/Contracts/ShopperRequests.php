<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * Shoppers waiting for the store (the Shoppers screen, the designer's redesign, 11.2026): the ones who left a phone or an
 * email when the assistant did not know, and the lead forms not handled yet. Each module that keeps such requests
 * answers for its own, under keys it alone reads ("callback:…", "lead:…"). The contact never reaches the page: replying
 * asks for a link (a WhatsApp chat or an email), and that is the moment a module may record who looked. No model.
 */
interface ShopperRequests
{
    public const TAG = 'admin.shopper_requests';

    /**
     * Who is waiting, the newest first. channel: phone or email. name: what they gave, or null. visitor: the shopper's
     * visitor hash as the visits log keeps it, so the screen can open the visit they asked from; null when unknown.
     *
     * @return list<array{key: string, name: string|null, channel: string, question: string, at: CarbonInterface, visitor: string|null}>
     */
    public function waiting(Shop $shop, int $limit): array;

    /** A link that opens the reply (wa.me or mailto), with the question quoted; null when the key is not this module's. */
    public function replyUrl(Shop $shop, string $key, ?int $userId): ?string;

    /** The store came back to them; it stops waiting. False when the key is not this module's. */
    public function answered(Shop $shop, string $key, ?int $userId): bool;

    /** People who left their details for updates and offers in the period (0 when the module keeps none). */
    public function signUps(Shop $shop, CarbonInterface $since, CarbonInterface $until): int;
}
