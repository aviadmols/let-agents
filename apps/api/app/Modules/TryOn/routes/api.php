<?php

use App\Modules\TryOn\Http\Controllers\TryOnController;
use Illuminate\Support\Facades\Route;

// The storefront's Try-On. Only the photo and the try reach a model, after the shopper's own action.
Route::get('try-on/let-agents-tryon.js', [TryOnController::class, 'script'])->name('api.try_on.script');
Route::get('try-on/file', [TryOnController::class, 'file'])->middleware('signed')->name('api.try_on.file');
Route::get('try-on/{site}/config', [TryOnController::class, 'config'])->name('api.try_on.config');
Route::post('try-on/{site}/photos', [TryOnController::class, 'photo'])->middleware('throttle:try-on')->name('api.try_on.photo');
Route::post('try-on/{site}/tries', [TryOnController::class, 'start'])->middleware('throttle:try-on')->name('api.try_on.start');
Route::get('try-on/{site}/tries/{id}', [TryOnController::class, 'show'])->name('api.try_on.show');
