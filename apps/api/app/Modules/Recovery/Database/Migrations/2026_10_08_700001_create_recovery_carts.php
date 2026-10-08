<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart a shopper left their email for before paying: the store keeps it as an order waiting for
 * payment, and we keep what is needed to explain it if it is not paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_carts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('platform', 20)->default('woocommerce');
            // The store's order: an hmac reference like every order report, and the real id and
            // edit link for the shop's own panel.
            $table->string('order_ref', 64);
            $table->string('store_order_id', 40)->nullable();
            $table->text('admin_url')->nullable();
            $table->text('email');
            $table->string('email_hash', 64);
            $table->string('email_masked', 120);
            $table->text('consent_text')->nullable();
            $table->timestamp('consented_at');
            $table->string('visitor_hash', 64)->nullable();
            $table->json('items');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            // What the shopper searched in this browser, sent with their consent.
            $table->json('searches')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('captured_at');
            $table->timestamp('converted_at')->nullable();
            $table->json('report')->nullable();
            $table->unsignedSmallInteger('report_version')->nullable();
            $table->boolean('report_checked')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'order_ref']);
            $table->index(['shop_id', 'status', 'captured_at']);
            $table->index(['shop_id', 'visitor_hash']);
            $table->index(['shop_id', 'email_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_carts');
    }
};
