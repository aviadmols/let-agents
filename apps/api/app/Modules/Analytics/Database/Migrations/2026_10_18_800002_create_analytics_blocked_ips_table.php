<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses a shop blocked from its analytics screen: search, the assistant and the visit beacon refuse
 * them (RefuseBlockedAddresses); the store itself is the store's and is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_blocked_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('ip', 45);
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_blocked_ips');
    }
};
