<?php

namespace App\Modules\Admin\Support;

use Illuminate\Support\HtmlString;

/**
 * The ⓘ the design puts beside a number's title: one more sentence on what it counts. Matched by the
 * title's own words, in the viewer's language, the way the designer's panel does it, so a screen needs
 * nothing of its own; the page adds the mark next to each title it finds (merchant panel only).
 */
final class Tips
{
    /** Each tip, and the label (a translation key) it explains. */
    private const LABELS = [
        'ai' => 'ai::budget.card.label',
        'questions' => 'assistant::home.card.label',
        'products' => 'enrichment::home.card.label',
        'pages' => 'catalog::home.card.label',
        'visitors' => 'admin::home.numbers.visitors',
        'adds' => 'admin::home.numbers.adds',
        'orders' => 'admin::home.numbers.orders',
        'revenue' => 'admin::home.numbers.revenue',
        'opens' => 'analytics::report.store.opens',
        'searches' => 'analytics::report.store.searches',
        'asks' => 'analytics::report.store.questions',
        'store_adds' => 'analytics::report.store.adds',
        'failed' => 'analytics::report.failed.title',
        'ips' => 'analytics::report.lists.ips.title',
        'sessions' => 'analytics::sessions.stats.sessions',
        'steps' => 'analytics::sessions.stats.steps',
        'scan_products' => 'enrichment::scan.stats.products',
        'scan_images' => 'enrichment::scan.stats.images',
        'scan_tags' => 'enrichment::scan.stats.tags',
        'scan_missing' => 'enrichment::scan.stats.missing',
    ];

    /** @return array<string, string> the label as shown => its tip */
    public static function map(): array
    {
        $map = [];

        foreach (self::LABELS as $key => $label) {
            $map[trim((string) __($label))] = (string) __('admin::tips.'.$key);
        }

        return $map;
    }

    public static function script(): HtmlString
    {
        return new HtmlString(view('admin::panel.tips', ['tips' => self::map()])->render());
    }
}
