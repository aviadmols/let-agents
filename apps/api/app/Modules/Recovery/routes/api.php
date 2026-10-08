<?php

use App\Modules\Recovery\Http\Controllers\CartConfigController;
use App\Modules\Recovery\Http\Controllers\CartScriptController;
use App\Modules\Recovery\Http\Controllers\PluginCartsController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader.
Route::get('cart/let-agents-cart.js', CartScriptController::class)->name('api.cart.script');

Route::get('cart/{site}/config', CartConfigController::class)
    ->middleware('throttle:cart')
    ->name('api.cart.config');

// The store's plugin, signed: a cart a shopper left their email for.
Route::post('plugin/{site}/carts', PluginCartsController::class)
    ->middleware('throttle:plugin-api')
    ->name('api.plugin.carts');
