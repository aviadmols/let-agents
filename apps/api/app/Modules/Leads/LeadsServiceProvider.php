<?php

namespace App\Modules\Leads;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Leads\Actions\DescribeLeadForm;
use App\Modules\Leads\Actions\OfferCallToAction;
use App\Modules\Leads\Console\ForgetOldLeadsCommand;
use App\Modules\Leads\Console\LeadsCommand;
use App\Modules\Leads\Contracts\DescribesLeadForm;
use App\Modules\Leads\Contracts\OffersCallsToAction;
use App\Modules\Leads\Listeners\ForgetShopperLeads;
use App\Modules\Leads\Support\LeadTimeline;
use App\Modules\Shopify\Events\ShopperDataRequested;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class LeadsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([LeadTimeline::class], TimelineSource::TAG);
        $this->app->bind(OffersCallsToAction::class, OfferCallToAction::class);
        $this->app->bind(DescribesLeadForm::class, DescribeLeadForm::class);
    }

    protected function moduleCommands(): array
    {
        return [
            ForgetOldLeadsCommand::class,
            LeadsCommand::class,
        ];
    }

    protected function bootModule(): void
    {
        // A lead is kept leads.retention_days (a year), then deleted with the person's details in it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('leads:forget-old')->dailyAt('03:55')->name('leads:forget-old')->withoutOverlapping();
        });

        // A shopper's request to be erased, passed on by Shopify: every lead they left by that email or phone.
        Event::listen(ShopperDataRequested::class, ForgetShopperLeads::class);

        // After the nightly reading, because everything a call to action is written from is what
        // that reading just found.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('leads compose --scheduled')
                ->dailyAt('01:00')
                ->timezone('Asia/Jerusalem')
                ->name('leads:compose-nightly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // Then the two models, on whatever the templates could only offer generically.
            $schedule->command('leads write --scheduled')
                ->dailyAt('01:20')
                ->timezone('Asia/Jerusalem')
                ->name('leads:write-nightly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // And last, what readers made of all of it — including whether the reviewer's scores
            // were worth anything.
            $schedule->command('leads learn --scheduled')
                ->dailyAt('01:40')
                ->timezone('Asia/Jerusalem')
                ->name('leads:learn-nightly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });

        // A form that can be posted to as fast as a script wants is a form that will be.
        RateLimiter::for('widget-lead', fn (Request $request): Limit => Limit::perMinute(
            (int) Settings::get('leads.steps_per_minute'),
        )->by($request->route('site').'|'.$request->ip()));
    }
}
