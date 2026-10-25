<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store someone asked to see Lets on, by its address and their email: the code that proves the email is theirs,
 * the demo store built from what the store publishes (a shop of its own, status demo, deleted with everything it
 * holds when the demo ends), the store's picture behind the demo, and when it ends. The row stays a while after,
 * without the picture, for the team to see who asked; the store's data does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // the secret part of the demo's address, never the id
            $table->string('token', 64)->unique();
            $table->string('url', 500);
            $table->string('host', 190)->index();
            $table->string('title', 190)->nullable();
            $table->string('email', 190)->index();
            $table->boolean('consent')->default(false);
            // what they said of the site: kind (store, content, both) and what matters in its search (look, content, both)
            $table->json('choices')->nullable();
            $table->string('locale', 5)->default('he');
            // the email's code, hashed, a few tries, a few minutes
            $table->string('code_hash', 100)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_tries')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            // what the store runs on, as read from its address: shopify, woocommerce or other
            $table->string('platform', 20)->nullable();
            // pending (the code is out), scanning, ready, failed, expired
            $table->string('status', 20)->default('pending')->index();
            $table->string('error', 200)->nullable();
            $table->unsignedInteger('products')->default(0);
            $table->unsignedInteger('pages')->default(0);
            // what its model calls cost, kept when its store is deleted
            $table->decimal('cost_usd', 10, 4)->nullable();
            // the store's home page as pictures (base64 JPEG): the container's disk does not last a deploy
            $table->longText('screenshot')->nullable();
            $table->longText('screenshot_mobile')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            // who asked, as a hash, for the day's limits only
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sites');
    }
};
