<?php

use App\Modules\SearchSubscriptions\Http\Controllers\SubscriptionLinksController;
use Illuminate\Support\Facades\Route;

// The links in Search Subscriptions' emails: signed, one tap, no login (SubscriptionLinksController).
Route::middleware(['signed', 'throttle:search-subscription-links'])->prefix('s')->group(function (): void {
    Route::get('confirm/{subscription}', [SubscriptionLinksController::class, 'confirm'])->name('search_subscriptions.confirm');
    Route::get('stop/{subscription}', [SubscriptionLinksController::class, 'stop'])->name('search_subscriptions.stop');
    Route::get('keep/{subscription}', [SubscriptionLinksController::class, 'keep'])->name('search_subscriptions.keep');
    Route::get('stop-all/{shop}/{address}', [SubscriptionLinksController::class, 'stopAll'])->name('search_subscriptions.stop_all');
    Route::get('manage/{shop}/{address}', [SubscriptionLinksController::class, 'manage'])->name('search_subscriptions.manage');
    Route::get('o/{send}', [SubscriptionLinksController::class, 'opened'])->name('search_subscriptions.opened');
    Route::get('c/{send}', [SubscriptionLinksController::class, 'clicked'])->name('search_subscriptions.clicked');
});
