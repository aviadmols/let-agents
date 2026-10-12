<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * Search answers are checked by Claude Haiku 5.5: a tenth of Haiku 4.5's price, from the same
 * family, so the writer (OpenAI) and the checker still come from different families. Only the model
 * and its room change; when the checker runs and what it decides stay as they were.
 *
 * Haiku 5.5 thinks before it answers and the thinking counts as output, so the check gets 1,500
 * tokens instead of 400; a check cut short would fail the question. Written through Settings, so the
 * cached values change with them, and changed back the same way.
 */
return new class extends Migration
{
    private const HAIKU_5_5 = [
        'assistant.site_check_provider' => 'anthropic',
        'assistant.site_check_model' => 'claude-haiku-5-5',
        'assistant.site_check_input_usd_per_million' => 0.1,
        'assistant.site_check_output_usd_per_million' => 0.5,
        'assistant.site_check_max_output_tokens' => 1500,
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
