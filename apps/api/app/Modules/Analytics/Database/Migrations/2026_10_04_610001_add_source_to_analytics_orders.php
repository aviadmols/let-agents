<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // live: reported at checkout. history: sent once by the plugin from the store's past
        // orders, so purchases can be learned from before Let Agents was installed. History has no
        // visitor and no attribution, and the report of what Let Agents did leaves it out.
        Schema::table('analytics_orders', function (Blueprint $table) {
            $table->string('source', 10)->default('live')->after('order_ref');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
