<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages;

use App\Modules\Tenancy\Actions\CreateShop as CreateShopAction;
use App\Modules\Tenancy\Actions\StartTrial;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\ShopResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    /** Creation goes through the action, so the panel and code create shops the same way. */
    protected function handleRecordCreation(array $data): Model
    {
        $trial = (bool) ($data['trial'] ?? false);
        unset($data['trial']);
        $shop = app(CreateShopAction::class)->handle($data);

        return $trial ? app(StartTrial::class)->handle($shop) : $shop;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
