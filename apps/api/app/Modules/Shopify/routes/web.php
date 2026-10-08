<?php

use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use Illuminate\Support\Facades\Route;

// The Shopify app, as a merchant meets it. Signed by Shopify; see the controller.
Route::middleware('throttle:shopify')->group(function (): void {
    Route::get('shopify/install', [ShopifyAppController::class, 'install'])->name('shopify.install');
    Route::get('shopify/callback', [ShopifyAppController::class, 'callback'])->name('shopify.callback');
    Route::get('shopify/app', [ShopifyAppController::class, 'open'])->name('shopify.app');
    Route::get('shopify/billing/return', [ShopifyAppController::class, 'billingReturn'])->name('shopify.billing.return');
});
