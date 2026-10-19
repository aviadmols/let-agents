<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People an owner invited to the shop's panel: the email the link went to, the role and panel language
 * they will have, and the link's token (its hash only). Accepted once; sent again with a new token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role', 20)->default('member');
            $table->string('locale', 10)->default('he');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_invitations');
    }
};
