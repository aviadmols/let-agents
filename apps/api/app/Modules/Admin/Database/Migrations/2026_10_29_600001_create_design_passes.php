<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A designer's pass into one shop's panel (Aviad, 29.10): a link that works for a day, signs in as the shop's design
 * account (a team member: everything a team member may change, nothing of the account), and lets the panel load the
 * designer's own stylesheet from their computer. Only its token's hash is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_passes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_passes');
    }
};
