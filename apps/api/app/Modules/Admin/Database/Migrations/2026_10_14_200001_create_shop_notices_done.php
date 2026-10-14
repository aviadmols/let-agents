<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notices a shop manager marked as done: by the notice's key, so a card the modules still
 * raise stays out of the home and the list until the manager brings it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_notices_done', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('key', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['shop_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_notices_done');
    }
};
