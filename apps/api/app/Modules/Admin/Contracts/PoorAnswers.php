<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The answers shoppers said did not help ("עזר? 👎" under an answer), for the store report: the question, what the
 * assistant said, and how many said so, the most first. One module answers it (Search, which keeps the ratings); counts
 * only, no model. Call inside the shop's tenant.
 */
interface PoorAnswers
{
    public const TAG = 'admin.poor_answers';

    /** @return list<array{question: string, answer: string, downs: int}> */
    public function poor(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array;
}
