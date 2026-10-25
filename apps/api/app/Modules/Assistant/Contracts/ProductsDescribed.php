<?php

namespace App\Modules\Assistant\Contracts;

/**
 * Which products' own descriptions say a name, as a phrase: "שיטאקה" is in the formulas whose ingredients list it,
 * though no title or tag says it. For a thing an answer recommends by name that is not among the products it was
 * given (answer_site v14's names): the store's products that hold it are shown with the answer. Never a model.
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round).
 * Call inside the shop's tenant.
 */
interface ProductsDescribed
{
    /** @return list<string> external ids, the earliest mention first; empty when none */
    public function saying(string $shopId, string $phrase): array;
}
