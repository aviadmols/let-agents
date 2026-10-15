<?php

use App\Modules\Search\Http\Controllers\SharePageController;
use Illuminate\Support\Facades\Route;

// A search a shopper handed to their own AI: public, by its unguessable token, never indexed.
Route::get('s/{token}', SharePageController::class)
    ->middleware('throttle:search-share')
    ->name('search.share');
