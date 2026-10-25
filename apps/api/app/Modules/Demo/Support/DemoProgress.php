<?php

namespace App\Modules\Demo\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Tenancy\Models\Shop;
use Throwable;

/**
 * A demo store's scan as the first-run screen draws it for a real store (the Admin module's onboarding): the
 * products, their pictures, the pages, the tags and the knowledge, each with its count so far and where it stands,
 * and what the scan reported, a line a part. Read from the same modules (ShopFacts, ShopScan), never a model.
 */
final class DemoProgress
{
    /** part => the fact it counts, and the fact it is counted against */
    private const ROWS = [
        'products' => ['products', null],
        'images' => ['pictures_read', 'products'],
        'pages' => ['pages_read', 'pages'],
        'tags' => ['tags', null],
        'knowledge' => ['answers', null],
    ];

    /**
     * @param  bool  $pictures  whether the demo reads pictures at all; when not, their part is not shown
     * @return array{finished: bool, started: bool, rows: list<array{key: string, n: int, of: int|null, state: string}>, learned: list<array{part: string, text: string}>, facts: array<string, mixed>}
     */
    public static function of(Shop $shop, bool $pictures = true): array
    {
        $tenant = app(TenantContext::class);
        $facts = [];
        foreach (app()->tagged(ShopFacts::TAG) as $source) {
            try {
                $facts = array_merge($facts, array_filter($tenant->run((string) $shop->id, fn (): array => $source->shopFacts($shop)), fn ($v): bool => $v !== null));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $scan = app()->bound(ShopScan::class) ? app(ShopScan::class) : null;
        $progress = $scan?->progress($shop);
        $rows = [];
        foreach (self::ROWS as $part => [$key, $against]) {
            if (! array_key_exists($key, $facts) || ($part === 'images' && ! $pictures)) {
                continue;
            }
            $n = (int) $facts[$key];
            $of = $against !== null && (int) ($facts[$against] ?? 0) > 0 ? (int) $facts[$against] : null;
            $state = $progress['parts'][$part] ?? ($n > 0 ? ShopScan::DONE : ShopScan::WAITING);
            $rows[] = [
                'key' => $part,
                'n' => $n,
                'of' => $of,
                // all of it counted is done, whatever part of the scan comes next
                'state' => $state === ShopScan::WAITING && $of !== null && $n >= $of ? ShopScan::DONE : $state,
            ];
        }

        $learned = $scan === null ? [] : array_values(array_filter($scan->learned($shop), fn (array $l): bool => $pictures || ($l['part'] ?? '') !== 'images'));

        return [
            'started' => $progress !== null,
            'finished' => $progress !== null && $progress['finished'],
            'rows' => $rows,
            'learned' => self::said($shop, $learned, $facts),
            'facts' => $facts,
        ];
    }

    /**
     * What a part done says on a demo's screen: its own words, not the scan's report for a store's team (what changed,
     * what was removed, the settings to raise): how many it read, and, at the demo's limit, that it stopped there and
     * the demo may show only part of the site.
     *
     * @param  list<array{part: string, text: string}>  $learned  the parts the scan reported done
     * @param  array<string, mixed>  $facts
     * @return list<array{part: string, text: string}>
     */
    public static function said(Shop $shop, array $learned, array $facts): array
    {
        $counts = ['products' => 'products', 'images' => 'pictures_read', 'pages' => 'pages_read', 'tags' => 'tags', 'knowledge' => 'answers'];
        $limits = ['products' => 'catalog.max_products', 'pages' => 'catalog.max_content_items'];
        $said = [];

        foreach ($learned as $line) {
            $part = (string) ($line['part'] ?? '');
            $n = (int) ($facts[$counts[$part] ?? ''] ?? 0);
            // the knowledge centre is said without a count: a few prepared answers are no measure of what it knows
            if (! isset($counts[$part]) || ($n === 0 && $part !== 'knowledge')) {
                continue;
            }
            $text = __('demo::page.scan.said.'.$part, ['count' => number_format($n)]);
            $limit = isset($limits[$part]) ? (int) Settings::get($limits[$part], $shop->id) : 0;
            if ($limit > 0 && $n >= $limit) {
                $text .= ' '.__('demo::page.scan.said.'.$part.'_limit', ['limit' => number_format($limit)]);
            }
            $said[] = ['part' => $part, 'text' => $text];
        }

        return $said;
    }
}
