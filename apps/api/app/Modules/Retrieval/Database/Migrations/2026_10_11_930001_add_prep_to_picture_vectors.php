<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which preparation a picture went through before its vector was made (PictureTrim::VERSION): a
 * picture embedded before its plain background was trimmed is embedded again, its description kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['retrieval_images', 'retrieval_gallery_images'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedTinyInteger('prep')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['retrieval_images', 'retrieval_gallery_images'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('prep');
            });
        }
    }
};
