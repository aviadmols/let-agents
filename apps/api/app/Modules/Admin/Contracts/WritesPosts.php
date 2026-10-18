<?php

namespace App\Modules\Admin\Contracts;

/**
 * A post on a shopper's question, asked for from the questions screen ("write a dedicated post"): a
 * draft is written and waits for the team's approval with the other suggestions. One module answers it.
 */
interface WritesPosts
{
    /** writing, cap (the month's posts are used up) or exists (one is already on its way). */
    public function postFromQuestion(string $shopId, string $question): string;
}
