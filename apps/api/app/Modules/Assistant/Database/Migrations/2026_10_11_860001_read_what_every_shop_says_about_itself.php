<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * What every product page repeats, read once now for the shops already here, on the queue: the
 * next sync would read it too, but a shop should not wait a night for its refunds and warranty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            Artisan::queue('assistant:knowledge', ['--all' => true])->onQueue('long');
        }
    }

    public function down(): void {}
};
