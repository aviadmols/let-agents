<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The day's report, written in code, in each of the panel's languages: each reader sees it in their account's. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_reviews', function (Blueprint $table): void {
            $table->json('digests')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('improvement_reviews', function (Blueprint $table): void {
            $table->dropColumn('digests');
        });
    }
};
