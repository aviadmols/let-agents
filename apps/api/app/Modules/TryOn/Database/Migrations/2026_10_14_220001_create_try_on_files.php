<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Try-On's pictures in the database while they are kept on a container's own disk (no bucket yet):
 * every service sees the database and it outlives a deploy, so a photo saved by one container is
 * found by the one that makes the picture. With a bucket (TRYON_DISK) this table stays empty.
 * Deleted with the files after try_on.retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('try_on_files', function (Blueprint $table) {
            $table->string('path', 191)->primary();
            $table->longText('bytes'); // base64
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('try_on_files');
    }
};
