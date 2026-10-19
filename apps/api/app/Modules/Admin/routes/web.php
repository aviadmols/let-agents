<?php

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

// An invitation to a shop's panel: the signed link in the email (checked in the controller, which says why a link
// no longer works), and the password it sets.
Route::middleware(['throttle:20,1'])->group(function (): void {
    Route::get('invitation/{invitation}/{token}', [InvitationController::class, 'show'])->name('admin.invitation');
    Route::post('invitation/{invitation}/{token}', [InvitationController::class, 'accept'])->name('admin.invitation.accept');
});

Route::get('/', HomeController::class)->name('home');
