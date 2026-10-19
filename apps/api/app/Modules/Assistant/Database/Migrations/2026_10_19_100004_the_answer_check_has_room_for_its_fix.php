<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * The answer check (verify_site.v6) writes a fix and a verdict per product besides its decision, and
 * Haiku 5.5's thinking counts as output: a check with three products came to 1,376 of its 1,500 tokens,
 * and one cut short failed the question. 3,000 gives it room; it is paid only for what it writes.
 * Changed only where the value is still the one stored before, so an operator's own value stays.
 */
return new class extends Migration
{
    private const KEY = 'assistant.site_check_max_output_tokens';

    public function up(): void
    {
        if (app()->runningUnitTests() || (int) Settings::get(self::KEY) !== 1500) {
            return;
        }

        Settings::set(self::KEY, 3000);
    }

    public function down(): void
    {
        if ((int) Settings::get(self::KEY) === 3000) {
            Settings::set(self::KEY, 1500);
        }
    }
};
