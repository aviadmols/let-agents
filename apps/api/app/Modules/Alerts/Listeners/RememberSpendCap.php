<?php

namespace App\Modules\Alerts\Listeners;

use App\Modules\Ai\Events\SpendCapHit;
use App\Modules\Alerts\Actions\FindProblems;
use Illuminate\Support\Facades\Cache;

/** A refused call kept for the next sweep (FindProblems), until the month it was refused in ends. Nothing is sent here. */
final class RememberSpendCap
{
    public function handle(SpendCapHit $event): void
    {
        Cache::put(FindProblems::CAP.($event->shopId ?? 'platform'), ['spent' => $event->spentUsd, 'cap' => $event->capUsd], now()->utc()->endOfMonth());
    }
}
