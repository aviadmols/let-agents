<?php

use App\Modules\Search\Http\Controllers\PluginSearchController;
use App\Modules\Search\Http\Controllers\SearchController;
use App\Modules\Search\Http\Controllers\SearchEventsController;
use App\Modules\Search\Http\Controllers\SearchIndexController;
use App\Modules\Search\Http\Controllers\SearchPhotoController;
use App\Modules\Search\Http\Controllers\SearchScriptController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront calls these from every visitor,
// except the plugin route, which the store's own server signs.
Route::get('search/rega-search.js', SearchScriptController::class)->name('api.search.script');

Route::middleware('throttle:search')->group(function (): void {
    Route::get('search/{site}/index', SearchIndexController::class)->name('api.search.index');
    Route::get('search/{site}', SearchController::class)->name('api.search.query');
    Route::post('search/{site}/events', SearchEventsController::class)->name('api.search.events');
});

// A photo costs one picture embedding, so it has its own, tighter bucket.
Route::post('search/{site}/photo', SearchPhotoController::class)
    ->middleware('throttle:search-photo')
    ->name('api.search.photo');

Route::get('plugin/{site}/search', PluginSearchController::class)
    ->middleware('throttle:plugin-api')
    ->name('api.plugin.search');
