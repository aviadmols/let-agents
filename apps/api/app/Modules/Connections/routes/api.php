<?php

use App\Modules\Connections\Http\Controllers\PluginPanelController;
use Illuminate\Support\Facades\Route;

// The panel inside wp-admin: the plugin asks, signed with its token, for a one-time sign-in address.
Route::post('plugin/{site}/panel-login', [PluginPanelController::class, 'issue'])
    ->middleware('throttle:plugin-panel')
    ->name('api.connections.panel_login');
