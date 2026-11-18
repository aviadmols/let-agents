<?php

use App\Modules\SearchSubscriptions\Http\Controllers\SubscribeController;
use App\Modules\SearchSubscriptions\Http\Controllers\SubscriptionLinksController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront drawer calls these (SubscribeController).
Route::middleware('throttle:search-subscriptions')->group(function (): void {
    Route::post('search/{site}/subscribe', [SubscribeController::class, 'subscribe'])->name('api.search_subscriptions.subscribe');
    Route::post('search/{site}/subscription', [SubscribeController::class, 'status'])->name('api.search_subscriptions.status');
    Route::post('search/{site}/subscription/stop', [SubscribeController::class, 'stop'])->name('api.search_subscriptions.stop');
});

// A mail client's own unsubscribe button (List-Unsubscribe-Post, RFC 8058): a signed POST with no form and no CSRF token.
Route::post('search-subscriptions/{shop}/{address}/unsubscribe', [SubscriptionLinksController::class, 'oneClick'])
    ->middleware('signed')->name('search_subscriptions.one_click');
