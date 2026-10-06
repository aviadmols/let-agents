<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far the plugin has got sending a shop's past orders: one row per shop, so the panel
        // can say "1,240 of 3,900 orders, from March 2025" while it is still going.
        Schema::create('analytics_order_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('expected')->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('stored')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->timestamp('oldest_ordered_at')->nullable();
            $table->timestamp('newest_ordered_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_order_imports');
    }
};
