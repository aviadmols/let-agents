<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which of our Shopify apps the store installed: the public one, or a custom one made for it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_installs', function (Blueprint $table) {
            $table->string('client_id', 64)->nullable()->after('shop_domain');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_installs', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });
    }
};
