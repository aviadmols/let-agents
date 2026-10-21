<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform's monthly allowance for model work (ai.monthly_spend_cap_usd, all shops together) is
 * $50, as Aviad asked (2026-10-21): room for moving the words' vectors to Gemini at once, the
 * measurements before it and a month's use. The default says so; an amount set below it on the
 * configuration screen is raised to it, one set above it is kept.
 */
return new class extends Migration
{
    private const KEY = 'ai.monthly_spend_cap_usd';

    private const AT_LEAST = 50.0;

    private const BEFORE = 6.0;

    public function up(): void
    {
        if ($this->own() !== null && $this->own() < self::AT_LEAST) {
            // Through the settings, so the amount is checked against its bounds and every cache forgets the old one.
            Settings::set(self::KEY, self::AT_LEAST);
        }
    }

    public function down(): void
    {
        if ($this->own() === self::AT_LEAST) {
            Settings::set(self::KEY, self::BEFORE);
        }
    }

    /** The amount set on the configuration screen for the whole platform, if one was. */
    private function own(): ?float
    {
        $value = DB::table('setting_overrides')->where('key', self::KEY)->where('scope', '*')->value('value');

        return $value === null ? null : (float) json_decode((string) $value, true);
    }
};
