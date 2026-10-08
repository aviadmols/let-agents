<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a question in the search box got no answer: what refused it, for the operator. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table) {
            $table->string('reason', 40)->nullable()->after('from');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
