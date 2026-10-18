<?php

use App\Modules\Connections\Http\Controllers\DownloadPluginController;
use App\Modules\Connections\Http\Controllers\PluginPanelController;
use Illuminate\Support\Facades\Route;

// Authentication is checked in the controller: the app has no generic "login" route to send
// guests to, only the panel logins.
Route::get('operator/plugin/download', DownloadPluginController::class)
    ->name('connections.plugin.download');

// The frame in wp-admin opens this once: the store's own account is signed in, into the shop's panel.
Route::get('plugin-login/{code}', [PluginPanelController::class, 'open'])
    ->middleware('throttle:plugin-panel')
    ->name('connections.panel_login');
