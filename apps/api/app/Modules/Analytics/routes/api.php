<?php

use App\Modules\Analytics\Http\Controllers\BeaconController;
use App\Modules\Analytics\Http\Controllers\PluginOrdersController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. No session, no CSRF: the widget authenticates by site
// key and origin, the plugin by signature.
Route::post('widget/{site}/events', BeaconController::class)
    ->middleware('throttle:widget-beacons')
    ->name('api.widget.events');

Route::middleware('throttle:plugin-api')->group(function (): void {
    Route::post('plugin/{site}/orders', [PluginOrdersController::class, 'store'])->name('api.plugin.orders');
    Route::post('plugin/{site}/orders/history', [PluginOrdersController::class, 'history'])->name('api.plugin.orders.history');
    Route::get('plugin/{site}/reports', [PluginOrdersController::class, 'report'])->name('api.plugin.reports');
});
