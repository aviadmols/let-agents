<?php

use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use App\Modules\Shopify\Http\Controllers\ShopperDataReportController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
    // The plan pressed inside the admin, proven by the session token App Bridge gives the page (no cookie, so no CSRF).
    // The web group's guard is PreventRequestForgery since Laravel 13: leaving out ValidateCsrfToken, its old name,
    // left the guard on, and every press was refused with 419 before the token was read (15.11).
    Route::post('shopify/billing/choose', [ShopifyAppController::class, 'choosePlanByToken'])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('shopify.billing.choose');
    // A credit pack bought in Shopify's billing (one-time), once ai.credit_purchase is on: signed by us, and back.
    Route::get('shopify/credits/start', [ShopifyAppController::class, 'startCredits'])->name('shopify.credits.start');
    Route::get('shopify/credits/return', [ShopifyAppController::class, 'creditsReturn'])->name('shopify.credits.return');
});

// Each shop's panel is on its own address ({slug}.shop_domain), so the Shopify admin keeps only the
// screen's path, and a refresh asks for it at the app's own address, where no panel answers. Only
// that address: the shops' own addresses keep their panels.
if (filled(config('upsell.shop_domain'))) {
    Route::domain((string) config('upsell.shop_domain'))->middleware('throttle:shopify')->group(function (): void {
        Route::get('{path}', [ShopifyAppController::class, 'open'])->where('path', ShopifyAppController::RELOAD_PATH)->name('shopify.app.reload');
    });
}

// What the store keeps about a shopper who asked for it through Shopify, as a file, for the store's owners.
Route::get('shopify/data-reports/{report}', ShopperDataReportController::class)
    ->middleware('throttle:20,10')
    ->where('report', '[0-9A-Za-z]{26}')
    ->name('shopify.data_report');
