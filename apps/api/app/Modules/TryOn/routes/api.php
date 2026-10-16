<?php

use App\Modules\TryOn\Http\Controllers\TryOnScriptController;
use Illuminate\Support\Facades\Route;

// Try-On was removed from the storefront. Only its old script address answers, with a script that does nothing,
// for stores still on an old plugin; the photo, try, config and file routes are gone.
Route::get('try-on/let-agents-tryon.js', TryOnScriptController::class)->name('api.try_on.script');
