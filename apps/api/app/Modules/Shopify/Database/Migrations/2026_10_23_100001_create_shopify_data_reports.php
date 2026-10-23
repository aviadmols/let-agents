<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shopper asked the store, through Shopify, for what it keeps about them: the report, gathered at once from every
 * module that keeps shopper data, encrypted, for the store's owners to download and send on. Found again by the
 * Shopify customer id and the email's code, so the shopper's later request to be erased takes it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_data_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // Shopify's id of the request (data_request.id): the same request sent again is one report
            $table->string('request_ref', 64)->nullable();
            $table->string('customer_ref', 32)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->longText('report');
            $table->timestamp('downloaded_at')->nullable();
            $table->foreignId('downloaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'request_ref']);
            $table->index(['shop_id', 'customer_ref']);
            $table->index(['shop_id', 'email_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_data_reports');
    }
};
