<?php

use App\Modules\Alerts\Http\Controllers\SchedulerBeatController;
use Illuminate\Support\Facades\Route;

// For an uptime monitor: is the scheduler still starting its commands (SchedulerBeat).
Route::get('up/scheduler', SchedulerBeatController::class)
    ->middleware('throttle:60,1')
    ->name('alerts.scheduler-beat');
