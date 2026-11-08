<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The panel opens in English from now on (the designer's redesign, 11.2026: English first, Hebrew one tap away and
 * remembered). Everyone who already signed in has been using it in Hebrew, the default until now, so their language is
 * kept as theirs: only people who never signed in, and everyone new, meet the English default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('locale')->whereNotNull('last_login_at')->update(['locale' => 'he']);
    }

    public function down(): void
    {
        // the people kept on Hebrew cannot be told from those who chose it since
    }
};
