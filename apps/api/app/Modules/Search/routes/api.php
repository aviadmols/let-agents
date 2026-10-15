<?php

use App\Modules\Search\Http\Controllers\AppMcpController;
use App\Modules\Search\Http\Controllers\McpController;
use App\Modules\Search\Http\Controllers\PluginSearchController;
use App\Modules\Search\Http\Controllers\SearchController;
use App\Modules\Search\Http\Controllers\SearchEventsController;
use App\Modules\Search\Http\Controllers\SearchIndexController;
use App\Modules\Search\Http\Controllers\SearchOrderController;
use App\Modules\Search\Http\Controllers\SearchPhotoController;
use App\Modules\Search\Http\Controllers\SearchScriptController;
use App\Modules\Search\Http\Controllers\ShareController;
use App\Modules\Search\Http\Controllers\SimilarController;
use App\Modules\Search\Http\Controllers\SizeHelpController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront calls these from every visitor,
// except the plugin route, which the store's own server signs.
Route::get('search/let-agents-search.js', SearchScriptController::class)->name('api.search.script');

Route::middleware('throttle:search')->group(function (): void {
    Route::get('search/{site}/index', SearchIndexController::class)->name('api.search.index');
    Route::get('search/{site}/similar', SimilarController::class)->name('api.search.similar');
    Route::get('search/{site}/sizes', SizeHelpController::class)->name('api.search.sizes');
    Route::get('search/{site}', SearchController::class)->name('api.search.query');
    Route::post('search/{site}/events', SearchEventsController::class)->name('api.search.events');
    Route::post('search/{site}/share', ShareController::class)->name('api.search.share');
    // The order an AI wrote, checked, for our script on the store's page to put in its cart.
    Route::get('search/{site}/order', SearchOrderController::class)->name('api.search.order');
    // The store as a connector for a shopper's own AI (Model Context Protocol over HTTP).
    Route::match(['get', 'post', 'delete'], 'mcp/{site}', McpController::class)->name('api.search.mcp');
});

// Lets Search as one app inside a shopper's AI (a ChatGPT plugin, a Claude connector), every store
// of it at one address. Its calls come from the AI's own servers, many shoppers to an address.
Route::match(['get', 'post', 'delete'], 'mcp', AppMcpController::class)
    ->middleware('throttle:search-app')
    ->name('api.search.app');

// A photo costs one picture embedding, so it has its own, tighter bucket.
Route::post('search/{site}/photo', SearchPhotoController::class)
    ->middleware('throttle:search-photo')
    ->name('api.search.photo');

Route::get('plugin/{site}/search', PluginSearchController::class)
    ->middleware('throttle:plugin-api')
    ->name('api.plugin.search');
