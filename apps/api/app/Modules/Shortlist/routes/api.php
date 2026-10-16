<?php

use App\Modules\Shortlist\Http\Controllers\ShortlistController;
use App\Modules\Shortlist\Http\Controllers\ShortlistScriptController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the search drawer's "saved" tab calls these from every visitor.
// The old buttons' script is retired: it answers with a script that does nothing, for stores on an old plugin.
Route::get('shortlist/let-agents-shortlist.js', ShortlistScriptController::class)->name('api.shortlist.script');

Route::middleware('throttle:shortlist')->group(function (): void {
    // Shopify's app proxy: /apps/letagents/wishlist on the store's own address, signed by Shopify. Before the
    // routes by site key, which would read "proxy" as one.
    Route::match(['get', 'post'], 'shortlist/proxy/wishlist', [ShortlistController::class, 'proxy'])->name('api.shortlist.proxy');
    Route::get('shortlist/{site}/config', [ShortlistController::class, 'config'])->name('api.shortlist.config');
    Route::get('shortlist/{site}/compare', [ShortlistController::class, 'compare'])->name('api.shortlist.compare');
    Route::post('shortlist/{site}/wishlist', [ShortlistController::class, 'wishlist'])->name('api.shortlist.wishlist');
    Route::get('shortlist/{site}/shared/{code}', [ShortlistController::class, 'shared'])->name('api.shortlist.shared');
    Route::post('shortlist/{site}/phone/code', [ShortlistController::class, 'phoneCode'])->name('api.shortlist.phone.code');
    Route::post('shortlist/{site}/phone/verify', [ShortlistController::class, 'phoneVerify'])->name('api.shortlist.phone.verify');
    Route::post('shortlist/{site}/phone/out', [ShortlistController::class, 'phoneOut'])->name('api.shortlist.phone.out');
});
