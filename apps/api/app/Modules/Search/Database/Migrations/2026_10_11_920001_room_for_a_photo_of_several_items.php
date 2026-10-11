<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * A photo of four rings, each with its details, stone and place, does not fit in 600 tokens: the
 * answer was cut and the reading failed. 1,500 leave room. Through Settings, so the cached value
 * changes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if ((int) Settings::get('search.photo_tags_max_output_tokens') < 1500) {
            Settings::set('search.photo_tags_max_output_tokens', 1500);
        }
    }

    public function down(): void {}
};
