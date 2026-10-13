<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SMS account the platform sends from (019 first; another provider is one driver), and every
 * message sent: for which shop, why, whether the provider took it. The number itself is kept
 * only masked: the log is for counting and for finding a failure, not for contacting anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 20)->unique();
            $table->string('username', 120);
            // Encrypted with APP_KEY.
            $table->text('token');
            $table->string('token_hint', 32)->nullable();
            $table->string('sender', 11);
            $table->boolean('active')->default(true);
            $table->string('last_error', 300)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('purpose', 40);
            $table->string('phone_masked', 20);
            $table->boolean('ok');
            $table->string('error', 300)->nullable();
            $table->string('provider_ref', 80)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_accounts');
    }
};
