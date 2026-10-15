<?php

use App\Modules\Search\Http\Controllers\SharePageController;
use App\Modules\Search\Http\Controllers\StoreCartController;
use App\Modules\Search\Http\Controllers\StoreCatalogController;
use App\Modules\Search\Http\Controllers\StoreSimilarController;
use Illuminate\Support\Facades\Route;

// A search a shopper handed to their own AI: public, by its unguessable token, never indexed.
Route::get('s/{token}', SharePageController::class)
    ->middleware('throttle:search-share')
    ->name('search.share');

// The whole store for a shopper's AI: the instructions on top, every product searchable, each with its buy link.
Route::get('c/{site}', StoreCatalogController::class)
    ->middleware('throttle:search-share')
    ->where('site', '[a-f0-9]{24}')
    ->name('search.catalog');

// The order an AI put together, as one link to the store's checkout.
Route::get('c/{site}/cart', StoreCartController::class)
    ->middleware('throttle:search-share')
    ->where('site', '[a-f0-9]{24}')
    ->name('search.cart');

// What looks like one product, from the pictures' vectors made at night: no model, no picture read now.
Route::get('c/{site}/similar/{product}', StoreSimilarController::class)
    ->middleware('throttle:search-share')
    ->where('site', '[a-f0-9]{24}')
    ->name('search.similar');
