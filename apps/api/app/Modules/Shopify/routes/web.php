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
    // Signed by us: the plans to choose from, and the one chosen, off to Shopify to approve.
    Route::get('shopify/plans', [ShopifyAppController::class, 'plans'])->name('shopify.plans');
    Route::get('shopify/billing/start', [ShopifyAppController::class, 'startPlan'])->name('shopify.billing.start');
});

// Each shop's panel is on its own address ({slug}.shop_domain), so the Shopify admin keeps only the
// screen's path, and a refresh asks for it at the app's own address, where no panel answers. Only
// that address: the shops' own addresses keep their panels.
if (filled(config('upsell.shop_domain'))) {
    Route::domain((string) config('upsell.shop_domain'))->middleware('throttle:shopify')->group(function (): void {
        Route::get('{path}', [ShopifyAppController::class, 'open'])->where('path', ShopifyAppController::RELOAD_PATH)->name('shopify.app.reload');
    });
}
