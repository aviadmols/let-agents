<?php

use App\Modules\Admin\Http\Controllers\DesignPassController;
use App\Modules\Admin\Http\Controllers\ExportShopDataController;
use App\Modules\Admin\Http\Controllers\HomeController;
use App\Modules\Admin\Http\Controllers\InvitationController;
use App\Modules\Admin\Http\Controllers\SwitchLocaleController;
use App\Modules\Admin\Http\Controllers\SwitchShopController;
use App\Modules\Admin\Http\Controllers\SwitchThemeController;
use Illuminate\Support\Facades\Route;

Route::get('admin/locale/{locale}', SwitchLocaleController::class)
    ->where('locale', '[a-z]{2}')
    ->name('admin.locale');

// Which shop the operator panel is looking at. Session-backed, so it survives a page change.
Route::post('admin/shop', SwitchShopController::class)->middleware(['web', 'auth'])->name('admin.shop');

// The display mode chosen in the top bar, kept on the person.
Route::post('admin/theme/{theme}', SwitchThemeController::class)->middleware(['web', 'auth'])->name('admin.theme');

// Everything a shop holds, as one ZIP, for its owner (Settings · Privacy and account).
Route::get('account/{shop}/export', ExportShopDataController::class)->middleware(['web', 'auth', 'throttle:6,10'])->name('admin.privacy.export');

// Those two are forms; their address opened again (a reload, back, a restored tab) is the panel, never "405".
Route::get('admin/shop', fn () => redirect()->to('/'))->middleware('web');
Route::get('admin/theme/{theme}', fn () => redirect()->to('/'))->middleware('web');

// An invitation to a shop's panel: the signed link in the email (checked in the controller, which says why a link
// no longer works), and the password it sets.
Route::middleware(['throttle:20,1'])->group(function (): void {
    Route::get('invitation/{invitation}/{token}', [InvitationController::class, 'show'])->name('admin.invitation');
    Route::post('invitation/{invitation}/{token}', [InvitationController::class, 'accept'])->name('admin.invitation.accept');
});

// a designer's day in one shop's panel (DesignPassController): the link, the stylesheet from their computer, the end
Route::middleware(['web'])->group(function (): void {
    Route::get('design/closed', [DesignPassController::class, 'closed'])->name('admin.design.closed');
    Route::post('design/css', [DesignPassController::class, 'css'])->middleware('auth')->name('admin.design.css');
    Route::get('design/{token}', [DesignPassController::class, 'enter'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('admin.design');
});

Route::get('/', HomeController::class)->name('home');
