<?php

use App\Modules\Assistant\Http\Controllers\AskController;
use App\Modules\Assistant\Http\Controllers\QuestionsController;
use App\Modules\Assistant\Http\Controllers\SiteAskController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront widget calls these.
Route::get('widget/{site}/questions', QuestionsController::class)
    ->middleware('throttle:widget-page')
    ->name('api.widget.questions');

Route::post('widget/{site}/ask', AskController::class)
    ->middleware('throttle:assistant-ask')
    ->name('api.widget.ask');

// A question typed in the store's search box, about the whole site. Same daily limits.
Route::post('search/{site}/ask', SiteAskController::class)
    ->middleware('throttle:assistant-ask')
    ->name('api.search.ask');
