<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shopper's subscription to one search: nothing is sent before they confirm it from their email. The email is
        // encrypted; email_hash (a keyed hash, the same in every store) is what lookups and erasure use.
        Schema::create('search_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->text('email');
            $table->string('email_hash', 64);
            $table->string('query', 200);
            // the words as code reads them (HebrewSearch::normalize): two spellings of one search are one
            $table->string('query_key', 200);
            $table->string('locale', 5)->default('he');
            // pending (not confirmed), active, stopped
            $table->string('status', 12)->default('pending');
            $table->string('stop_reason', 24)->nullable();
            $table->text('consent_text');
            $table->timestamp('consent_at');
            $table->string('consent_ip_hash', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('confirm_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'email_hash', 'query_key']);
            $table->index(['shop_id', 'status']);
            $table->index('email_hash');
        });

        // One email to one address of one store (weekly, or the reminder before a subscription ends): what it held, and
        // whether it was opened or clicked.
        Schema::create('search_subscription_sends', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('email_hash', 64);
            $table->string('kind', 12)->default('weekly');
            $table->json('queries');
            $table->unsignedSmallInteger('products')->default(0);
            $table->timestamp('sent_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'email_hash', 'sent_at']);
        });

        // A product sent to an address: never sent to it again.
        Schema::create('search_subscription_products', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('email_hash', 64);
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('search_subscriptions')->nullOnDelete();
            $table->timestamp('sent_at');

            $table->unique(['shop_id', 'email_hash', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_subscription_products');
        Schema::dropIfExists('search_subscription_sends');
        Schema::dropIfExists('search_subscriptions');
    }
};
