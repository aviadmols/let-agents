<?php

use App\Modules\SearchSubscriptions\Http\Controllers\BounceController;
use App\Modules\SearchSubscriptions\Http\Controllers\SubscribeController;
use App\Modules\SearchSubscriptions\Http\Controllers\SubscriptionLinksController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront drawer calls these (SubscribeController).
// A sign-up sends an email: its own tight limit per IP and store.
Route::post('search/{site}/subscribe', [SubscribeController::class, 'subscribe'])->middleware('throttle:search-subscriptions')->name('api.search_subscriptions.subscribe');
// The drawer asking whether a search it subscribed to is confirmed, or stopping it from the bar: no email, a looser limit.
Route::middleware('throttle:search-subscription-links')->group(function (): void {
    Route::post('search/{site}/subscription', [SubscribeController::class, 'status'])->name('api.search_subscriptions.status');
    Route::post('search/{site}/subscription/stop', [SubscribeController::class, 'stop'])->name('api.search_subscriptions.stop');
});

// A mail client's own unsubscribe button (List-Unsubscribe-Post, RFC 8058): a signed POST with no form and no CSRF token.
Route::post('search-subscriptions/{shop}/{address}/unsubscribe', [SubscriptionLinksController::class, 'oneClick'])
    ->middleware('signed')->name('search_subscriptions.one_click');

// Resend's webhook: a hard bounce or a spam complaint stops every search of that address (signed, BounceController).
Route::post('search-subscriptions/bounces', BounceController::class)->middleware('throttle:search-subscription-bounces')->name('search_subscriptions.bounces');
