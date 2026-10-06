<?php

namespace App\Modules\Widget\Contracts;

/**
 * How the widget's content for one page is put together, for the panel: the same bank a
 * shopper would get, with `explain` (why each section and item is there) and `trace` (the
 * sections after each stage: built, allowed, learned, curated). Never cached, never for the
 * storefront.
 */
interface ExplainsPages
{
    /** @return array<string, mixed> */
    public function explain(string $shopId, string $type, string $externalId, string $locale): array;
}
