<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The weekly email is planned in one job and mailed by others (SendWeekly, DeliverWeeklyEmails):
        // - period: the store's week it belongs to (2026-W47, in the store's time). Unique with the address, so a run that
        //   repeats (a retried job, the hour twice) can never plan a second weekly email for one address in that week;
        // - mailed_at: when the mail service took it, so a retried delivery never mails it twice.
        Schema::table('search_subscription_sends', function (Blueprint $table) {
            $table->string('period', 10)->nullable()->after('kind');
            $table->timestamp('mailed_at')->nullable()->after('sent_at');

            $table->unique(['shop_id', 'email_hash', 'kind', 'period']);
        });

        // Which email carried the product: what the delivery reads, and what it takes back when the email never went.
        Schema::table('search_subscription_products', function (Blueprint $table) {
            $table->foreignUlid('send_id')->nullable()->after('subscription_id')->constrained('search_subscription_sends')->nullOnDelete();
        });

        // The weekly run walks a store's active addresses in order, a slice at a time.
        Schema::table('search_subscriptions', function (Blueprint $table) {
            $table->index(['shop_id', 'status', 'email_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('search_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'status', 'email_hash']);
        });
        Schema::table('search_subscription_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('send_id');
        });
        Schema::table('search_subscription_sends', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'email_hash', 'kind', 'period']);
            $table->dropColumn(['period', 'mailed_at']);
        });
    }
};
