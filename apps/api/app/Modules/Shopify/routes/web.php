<?php

use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use Illuminate\Support\Facades\Route;

// The Shopify app, as a merchant meets it. Signed by Shopify; see the controller.
Route::middleware('throttle:shopify')->group(function (): void {
    Route::get('shopify/install', [ShopifyAppController::class, 'install'])->name('shopify.install');
    Route::get('shopify/callback', [ShopifyAppController::class, 'callback'])->name('shopify.callback');
    Route::get('shopify/app', [ShopifyAppController::class, 'open'])->name('shopify.app');
    // A refresh inside Shopify asks for the screen the merchant was on, after the app address.
    Route::get('shopify/app/{path}', [ShopifyAppController::class, 'open'])->where('path', 'merchant(/[A-Za-z0-9_-]+)*')->name('shopify.app.deep');
    Route::get('shopify/billing/return', [ShopifyAppController::class, 'billingReturn'])->name('shopify.billing.return');
});
