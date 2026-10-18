<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A post asked for from the questions screen has no daily review behind it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_proposals', function (Blueprint $table): void {
            $table->foreignUlid('review_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('improvement_proposals', function (Blueprint $table): void {
            $table->foreignUlid('review_id')->nullable(false)->change();
        });
    }
};
