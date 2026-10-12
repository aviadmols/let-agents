<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * The catalogue's pictures are described by Claude Haiku 5.5: a tenth of Haiku 4.5's price, and in
 * the benchmark on the store's own products it named more of what a picture shows. It thinks before
 * it answers and the thinking counts as output, so the reply gets room; a reply cut short is not read.
 *
 * A picture's description is keyed by its model, so every picture is described once more by the new
 * one, a part at a time. Written through Settings, so the cached values change with them, and changed
 * back the same way.
 */
return new class extends Migration
{
    private const HAIKU_5_5 = [
        'retrieval.caption_provider' => 'anthropic',
        'retrieval.caption_model' => 'claude-haiku-5-5',
        'retrieval.caption_input_usd_per_million' => 0.1,
        'retrieval.caption_output_usd_per_million' => 0.5,
        'retrieval.caption_max_output_tokens' => 2000,
    ];

    public function up(): void
    {
        // The defaults say the same; the stored values are for a live system whose old ones were kept.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach (self::HAIKU_5_5 as $key => $value) {
            Settings::set($key, $value);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::HAIKU_5_5) as $key) {
            Settings::clear($key);
        }
    }
};
