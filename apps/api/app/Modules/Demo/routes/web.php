<?php

use App\Modules\Demo\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

// The demo: the address and the email, the code, the scan, the demo itself (DemoController).
Route::get('demo', [DemoController::class, 'form'])->name('demo.form');
Route::post('demo', [DemoController::class, 'start'])->middleware('throttle:demo-start')->name('demo.start');
Route::get('demo/{token}', [DemoController::class, 'show'])->middleware('throttle:demo-page')->name('demo.show');
Route::post('demo/{token}/verify', [DemoController::class, 'verify'])->middleware('throttle:demo-code')->name('demo.verify');
Route::post('demo/{token}/code', [DemoController::class, 'code'])->middleware('throttle:demo-code')->name('demo.code');
Route::post('demo/{token}/again', [DemoController::class, 'again'])->middleware('throttle:demo-start')->name('demo.again');
Route::get('demo/{token}/progress', [DemoController::class, 'progress'])->middleware('throttle:demo-page')->name('demo.progress');
Route::get('demo/{token}/phone', [DemoController::class, 'phone'])->middleware('throttle:demo-page')->name('demo.phone');
