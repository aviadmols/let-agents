<?php

use App\Modules\Improvement\Http\Controllers\GuideController;
use Illuminate\Support\Facades\Route;

// A published post of a store's own, read by shoppers. Public; cached briefly.
Route::get('g/{shop}/{post}', GuideController::class)->where(['shop' => '[a-z0-9-]+', 'post' => '[0-9A-Za-z]+'])->name('improvement.guide');
