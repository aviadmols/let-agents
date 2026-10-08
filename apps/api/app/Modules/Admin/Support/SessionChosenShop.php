<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Contracts\ChosenShop;

final class SessionChosenShop implements ChosenShop
{
    public function get(): ?array
    {
        $shop = CurrentShop::shop();

        return $shop === null ? null : ['id' => (string) $shop->id, 'slug' => (string) $shop->slug, 'name' => (string) $shop->name];
    }
}
