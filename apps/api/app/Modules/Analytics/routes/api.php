<?php

use App\Modules\Analytics\Http\Controllers\BeaconController;
use App\Modules\Analytics\Http\Controllers\PixelOrdersController;
use App\Modules\Analytics\Http\Controllers\PluginOrdersController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. No session, no CSRF: the widget authenticates by site
// key and origin, the plugin by signature.
Route::post('widget/{site}/events', BeaconController::class)
    ->middleware('throttle:widget-beacons')
    ->name('api.widget.events');

// The Shopify web pixel at checkout: reported from the shopper's browser (see PixelOrdersController).
Route::post('pixel/{site}/orders', PixelOrdersController::class)
    ->middleware('throttle:pixel-orders')
    ->name('api.pixel.orders');

Route::middleware('throttle:plugin-api')->group(function (): void {
    Route::post('plugin/{site}/orders', [PluginOrdersController::class, 'store'])->name('api.plugin.orders');
    Route::post('plugin/{site}/orders/history', [PluginOrdersController::class, 'history'])->name('api.plugin.orders.history');
    Route::get('plugin/{site}/reports', [PluginOrdersController::class, 'report'])->name('api.plugin.reports');
});
