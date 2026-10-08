<?php

namespace App\Modules\Retrieval\Contracts;

/** The shop's pictures by what they show: words compared with each picture's description vector. */
interface PictureContent
{
    /**
     * @return list<array{external_id: string, similarity: float}> nearest first; empty when no picture is described
     */
    public function picturesNearWords(string $shopId, string $text, int $limit): array;
}
