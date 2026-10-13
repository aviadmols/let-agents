<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The search chat asks for a name with the way back, so the team knows whom it calls. Kept
 * encrypted, like the contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopper_callbacks', function (Blueprint $table): void {
            $table->text('name')->nullable()->after('identity_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopper_callbacks', fn (Blueprint $table) => $table->dropColumn('name'));
    }
};
