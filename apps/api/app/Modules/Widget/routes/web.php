<?php

use App\Modules\Widget\Http\Controllers\WidgetPreviewController;
use Illuminate\Support\Facades\Route;

// The module as a shop's shoppers would see it, drawn inside the admin panel. Signed-in only;
// a shop manager sees only their own shop, an operator any shop.
Route::get('widget-preview/{shop:slug}', WidgetPreviewController::class)
    ->name('widget.preview');
