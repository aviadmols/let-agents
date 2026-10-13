<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned carts (the Recovery module, ADR 0009) are gone from the system: its table, empty when
 * it was removed, and what shops had saved for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('recovery_carts');
        DB::table('feature_overrides')->where('key', 'like', 'recovery.%')->delete();
        DB::table('setting_overrides')->where('key', 'like', 'recovery.%')->delete();
    }

    public function down(): void
    {
        // Not brought back: the module and its code are gone.
    }
};
