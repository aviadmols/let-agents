<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Pages;

use App\Modules\Admin\Filament\Operator\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** An account the team makes: its address is the team's word (InstallStore trusts an address only once proven). */
    protected function afterCreate(): void
    {
        $this->record->forceFill(['email_verified_at' => now()])->save();
    }
}
