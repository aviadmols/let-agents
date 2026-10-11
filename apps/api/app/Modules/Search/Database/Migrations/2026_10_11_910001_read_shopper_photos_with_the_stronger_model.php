<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * A shopper's photo is read, and its results ordered, by the stronger model: a few searches a day,
 * each worth getting right, where the small model mistook a colour for a stone and a plain band for
 * a set one. The pictures of the catalogue, thousands of them, stay with the small one. Written
 * through Settings, so the cached values change with them, and changed back the same way.
 */
return new class extends Migration
{
    private const STRONGER = [
        'search.photo_tags_model' => 'claude-sonnet-5-5',
        'search.photo_tags_input_usd_per_million' => 3.0,
        'search.photo_tags_output_usd_per_million' => 15.0,
        'search.photo_rank_model' => 'claude-sonnet-5-5',
        'search.photo_rank_input_usd_per_million' => 3.0,
        'search.photo_rank_output_usd_per_million' => 15.0,
    ];

    public function up(): void
    {
        // The defaults say the same; the stored values are for a live system whose old ones were kept.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach (self::STRONGER as $key => $value) {
            Settings::set($key, $value);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::STRONGER) as $key) {
            Settings::clear($key);
        }
    }
};
