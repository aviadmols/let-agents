<?php

use App\Modules\Shopify\Http\Controllers\ShopifyWebhooksController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Signed by Shopify with the app's secret.
Route::post('shopify/webhooks', ShopifyWebhooksController::class)
    ->middleware('throttle:shopify')
    ->name('api.shopify.webhooks');
