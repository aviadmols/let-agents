<?php

namespace App\Modules\Alerts\Http\Controllers;

use App\Modules\Alerts\Support\SchedulerBeat;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/up/scheduler, for an uptime monitor outside the system: 200 while the scheduler beats, 503 when it
 * stopped. The alerts sweep runs on the scheduler, so a stopped scheduler cannot tell of itself; this can.
 * Says only whether and when, nothing about any store.
 */
final class SchedulerBeatController
{
    public function __invoke(SchedulerBeat $beat): JsonResponse
    {
        return response()->json([
            'ok' => $beat->alive(),
            'last' => $beat->last()?->toIso8601String(),
        ], $beat->alive() ? 200 : 503);
    }
}
