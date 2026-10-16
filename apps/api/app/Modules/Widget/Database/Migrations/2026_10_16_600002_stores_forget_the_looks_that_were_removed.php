<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The storefront runs one design, the search drawer (2026-10-16). The choices stores had made about the
 * old ones (the module's look and where it sat, the search's mode and drawer, the shortlist's hearts,
 * Try-On) are no longer declared, and nothing reads them; their saved values are taken off every store
 * and the platform's defaults, so no store keeps an answer to a question nobody asks any more.
 *
 * Runs after the page-tags migration, which reads widget.layout first. Nothing comes back on the way
 * down: the keys are gone from the code, so a restored row would mean nothing.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const SETTINGS = [
        'widget.layout', 'widget.product_selector', 'widget.product_position', 'widget.content_selector',
        'widget.content_position', 'widget.floating_fallback',
        'search.results', 'search.suggestions', 'search.dropdown_layout', 'search.drawer_side',
        'search.drawer_columns', 'search.groups', 'search.hover_image', 'search.button_color',
        'shortlist.card_selector', 'shortlist.product_selector', 'shortlist.wishlist_floating',
        'shortlist.wishlist_header', 'shortlist.heart_plain', 'shortlist.wishlist_place',
        'shortlist.card_finder_provider', 'shortlist.card_finder_model', 'shortlist.card_finder_input_usd_per_million',
        'shortlist.card_finder_output_usd_per_million', 'shortlist.card_finder_max_output_tokens',
    ];

    /** @var list<string> */
    private const FEATURES = ['widget.find_field', 'search.mode_choice'];

    public function up(): void
    {
        DB::table('setting_overrides')->whereIn('key', self::SETTINGS)->orWhere('key', 'like', 'try_on.%')->delete();
        DB::table('feature_overrides')->whereIn('key', self::FEATURES)->delete();

        Cache::forget('core:setting_overrides');
        Cache::forget('core:feature_overrides');
    }

    public function down(): void {}
};
