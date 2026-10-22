<?php

namespace App\Modules\Shoppers;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Actions\ReadContact;
use App\Modules\Shoppers\Contracts\ReadsContacts;
use App\Modules\Shoppers\Contracts\VisitHistory;
use App\Modules\Shoppers\Listeners\ForgetShopperContact;
use App\Modules\Shoppers\Models\ShopperVerification;
use App\Modules\Shoppers\Support\Visits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class ShoppersServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(VisitHistory::class, Visits::class);
        $this->app->bind(ReadsContacts::class, ReadContact::class);
    }

    protected function bootModule(): void
    {
        RateLimiter::for('shoppers-signup', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('shoppers.signups_per_minute'))
            ->by('shoppers-signup:'.$request->ip().'|'.$request->route('site')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [ShopperVerification::class]])
                ->dailyAt('03:50')
                ->name('shoppers:prune-codes');
        });

        // A shopper's request to be erased, passed on by Shopify: the identity they left by email or phone.
        Event::listen(ShopperDataRequested::class, ForgetShopperContact::class);
    }
}
