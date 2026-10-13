<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wishlist kept by the shopper's phone: a four-digit code by SMS, then the list is the number's
 * on every device. The number is kept encrypted on the list, with whether the shopper agreed to
 * hear from the store; codes and sign-ins only as hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlist_wishlists', function (Blueprint $table) {
            // Encrypted with APP_KEY.
            $table->text('phone')->nullable()->after('customer_ref');
            $table->boolean('marketing_consent')->default(false)->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('marketing_consent');
        });

        Schema::create('shortlist_phone_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('phone_key', 64);
            $table->string('code_hash', 64);
            $table->string('ip_key', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'phone_key', 'created_at']);
            $table->index(['shop_id', 'ip_key', 'created_at']);
        });

        Schema::create('shortlist_phone_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('phone_key', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlist_phone_sessions');
        Schema::dropIfExists('shortlist_phone_codes');
        Schema::table('shortlist_wishlists', function (Blueprint $table) {
            $table->dropColumn(['phone', 'marketing_consent', 'phone_verified_at']);
        });
    }
};
