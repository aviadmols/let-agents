<?php

namespace App\Modules\Widget\Support;

use App\Modules\Widget\Actions\BuildPageBank;
use App\Modules\Widget\Contracts\ExplainsPages;

final class PageExplainer implements ExplainsPages
{
    public function __construct(private readonly BuildPageBank $bank) {}

    public function explain(string $shopId, string $type, string $externalId, string $locale): array
    {
        return $this->bank->handle($shopId, $type, $externalId, $locale, explain: true);
    }
}
