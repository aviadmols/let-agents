<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An email is trusted as a person's only once it is proven theirs (1.11): a Shopify install makes an account its store's
 * owner by its address only when the address is proven (email_verified_at), and an invitation proves the address only when
 * it reached that inbox (mailed_at), not when its link was handed over. The accounts there are now stand as they were:
 * proven, so no store or person loses what they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_invitations', function (Blueprint $table): void {
            $table->timestamp('mailed_at')->nullable()->after('sent_at');
        });

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('shop_invitations', function (Blueprint $table): void {
            $table->dropColumn('mailed_at');
        });
    }
};
