<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs\Pages;

use App\Modules\Runs\Filament\Operator\Resources\Runs\RunResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;

final class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    /** A run opens beside the list, in a side panel; its own page stays for the links from other screens. */
    public function getDefaultActionUrl(Action $action): ?string
    {
        return $action instanceof ViewAction ? null : parent::getDefaultActionUrl($action);
    }
}
