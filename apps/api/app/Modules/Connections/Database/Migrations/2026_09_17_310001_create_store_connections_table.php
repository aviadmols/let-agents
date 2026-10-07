<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->string('platform', 20)->default('woocommerce');
            $table->string('site_url');
            // Encrypted with APP_KEY. Holds the token the store's Let Agents plugin issued.
            $table->text('access_token');
            $table->string('token_prefix', 16)->nullable();
            $table->string('status', 20)->default('untested')->index();
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('site_info')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_connections');
    }
};
