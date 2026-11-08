<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What shoppers save for later (the Shoppers screen, the designer's redesign, 11.2026): the saved lists changed in the
 * period and the products most of them hold. One module answers it (Shortlist). No model.
 */
interface SavedProducts
{
    public const TAG = 'admin.saved_products';

    /** @return array{lists: int, products: int} lists changed in the period, and the products in them */
    public function numbers(Shop $shop, CarbonInterface $since, CarbonInterface $until): array;

    /**
     * The products in the most lists, the most first. id: the store's product id.
     *
     * @return list<array{id: string, title: string, picture: string|null, saved: int}>
     */
    public function top(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;

    /** Every list as a spreadsheet; a phone only where the shopper agreed to hear from the store. */
    public function export(Shop $shop): StreamedResponse;
}
