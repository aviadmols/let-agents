<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages;

use App\Modules\Tenancy\Actions\CreateShop as CreateShopAction;
use App\Modules\Tenancy\Actions\StartTrial;
use App\Modules\Tenancy\Enums\SearchMatters;
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
        $matters = SearchMatters::tryFrom((string) ($data['matters'] ?? '')) ?? SearchMatters::Content;
        unset($data['trial'], $data['matters']);
        $shop = app(CreateShopAction::class)->handle($data);

        // what matters, before a trial: the trial keeps it and gives it back at its end
        $shop->forceFill(['profile' => ['matters' => $matters->value] + (array) ($shop->profile ?? [])])->save();
        $matters->apply($shop);

        return $trial ? app(StartTrial::class)->handle($shop) : $shop;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
