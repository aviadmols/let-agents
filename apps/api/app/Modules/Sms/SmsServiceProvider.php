<?php

namespace App\Modules\Sms;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Sms\Support\Sms019;
use App\Modules\Sms\Support\SmsService;

final class SmsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // Every provider a driver: another one is a class tagged here.
        $this->app->tag([Sms019::class], 'sms.drivers');
        $this->app->bind(SendsSms::class, fn ($app): SmsService => new SmsService($app->tagged('sms.drivers')));
    }

    protected function bootModule(): void
    {
        //
    }
}
