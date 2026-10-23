<?php

namespace App\Modules\Admin;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Console\CreateOperatorCommand;
use App\Modules\Admin\Console\DeleteDueShopsCommand;
use App\Modules\Admin\Console\EndDuePlansCommand;
use App\Modules\Admin\Console\SetPasswordCommand;
use App\Modules\Admin\Contracts\ChosenShop;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Admin\Http\Responses\RoleAwareLoginResponse;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Panels\MerchantPanelProvider;
use App\Modules\Admin\Panels\OperatorPanelProvider;
use App\Modules\Admin\Support\AdminTimeline;
use App\Modules\Admin\Support\SessionChosenShop;
use App\Modules\Admin\Support\ViewerGuard;
use App\Modules\Tenancy\Models\Shop;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

final class AdminServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([AdminTimeline::class], TimelineSource::TAG);
        $this->app->bind(ChosenShop::class, SessionChosenShop::class);
        $this->app->register(OperatorPanelProvider::class);
        $this->app->register(MerchantPanelProvider::class);

        // Replaces Filament's default, which always returns to the panel the login was on.
        $this->app->bind(LoginResponse::class, RoleAwareLoginResponse::class);
    }

    protected function bootModule(): void
    {
        // The settings pages' controls, as the designer's panel draws them: <x-lp::toggle>, <x-lp::chips>…
        Blade::anonymousComponentPath(__DIR__.'/resources/views/components', 'lp');

        // What a person may do in a shop: change it (everyone but a viewer), manage its people and connection (an owner).
        Gate::define('edit-shop', fn (User $user, Shop|string $shop): bool => $user->canEditShop($shop));
        Gate::define('manage-shop', fn (User $user, Shop|string $shop): bool => $user->managesShop($shop));

        // A viewer reads the panel and changes nothing: every button press passes the guard first.
        \Livewire\on('call', ViewerGuard::call(...));

        // When each person last came in, for "who signs in to the account".
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // The shops whose owners asked for them to be deleted, on their day (Settings · Privacy and account).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('shops:delete-due')->dailyAt('04:10')->name('shops:delete-due')->withoutOverlapping();
            // A cancelled plan that reached the end of the period already paid: the shop pauses within the hour.
            $schedule->command('shops:end-due-plans')->hourlyAt(5)->name('shops:end-due-plans')->withoutOverlapping();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            CreateOperatorCommand::class,
            DeleteDueShopsCommand::class,
            EndDuePlansCommand::class,
            SetPasswordCommand::class,
        ];
    }
}
