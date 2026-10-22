<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres cannot compare a json column, so "select distinct shops.*" fails, and Filament asks it so for a user's
 * shops (the operator's user screen answered 500). jsonb compares. SQLite has one json kind: nothing to do.
 */
return new class extends Migration
{
    private const COLUMNS = ['profile', 'trial_saved'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("alter table shops alter column {$column} type jsonb using {$column}::jsonb");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            DB::statement("alter table shops alter column {$column} type json using {$column}::json");
        }
    }
};
