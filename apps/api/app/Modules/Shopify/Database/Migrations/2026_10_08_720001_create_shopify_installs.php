<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Shopify store that installed the app: its tokens (encrypted, refreshed before they expire)
 * and its subscription. The shop and its store connection live in their own modules; this is
 * what only Shopify needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_installs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('shop_domain', 255)->unique();
            $table->text('access_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('scopes', 1000)->nullable();
            $table->string('owner_email', 255)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            // The app subscription: none, pending (waiting for the merchant to approve), active,
            // declined, cancelled, frozen or expired, as Shopify reports it.
            $table->string('subscription_id', 255)->nullable();
            $table->string('subscription_status', 20)->default('none');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();

            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_installs');
    }
};
