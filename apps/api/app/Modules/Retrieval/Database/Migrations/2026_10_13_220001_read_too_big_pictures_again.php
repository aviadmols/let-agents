<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pictures on Shopify are now fetched resized (PictureAddress), so one skipped as too big is read
 * again at the next scan, as the main pictures and the gallery are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('retrieval_images')->where('error', 'too_big')->update(['error' => null]);
        DB::table('retrieval_gallery_images')->where('error', 'too_big')->update(['error' => null]);
    }

    public function down(): void
    {
        //
    }
};
