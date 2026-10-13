<?php

namespace App\Modules\Widget;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Widget\Contracts\ExplainsPages;
use App\Modules\Widget\Support\PageExplainer;
use App\Modules\Widget\Support\WidgetHome;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class WidgetServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // What this module shows on the shop's home and notices.
        $this->app->tag([WidgetHome::class], HomeSource::TAG);
        $this->app->bind(ExplainsPages::class, PageExplainer::class);
    }

    protected function bootModule(): void
    {
        RateLimiter::for('widget-page', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('widget.page_requests_per_minute'))
            ->by('widget-page:'.$request->ip().'|'.$request->route('site')));

        RateLimiter::for('widget-recent', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('widget.page_requests_per_minute'))
            ->by('widget-recent:'.$request->ip().'|'.$request->route('site')));
    }
}
