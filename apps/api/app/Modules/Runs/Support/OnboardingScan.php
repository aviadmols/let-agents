<?php

namespace App\Modules\Runs\Support;

use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Tenancy\Models\Shop;

/**
 * The store's scan (FirstScan) as the first-run screens draw it: its steps gathered into the five parts a
 * shop manager reads (the products, their pictures, the pages, the tags, the knowledge), and the summary
 * line each step's runs wrote, already free of models and costs (FirstScan::notes).
 */
final class OnboardingScan implements ShopScan
{
    /** part => the scan's steps that make it, in the scan's order */
    public const PART_STEPS = [
        'products' => ['catalog', 'sample'],
        'images' => ['pictures'],
        'pages' => ['site', 'posts', 'pages'],
        'tags' => ['structure', 'products', 'search', 'tags'],
        'knowledge' => ['knowledge'],
    ];

    public function progress(Shop $shop): ?array
    {
        $state = FirstScan::state($shop);

        if ($state === null) {
            return null;
        }

        $parts = [];
        foreach (self::PART_STEPS as $part => $steps) {
            $states = array_values(array_intersect_key($state['steps'], array_flip($steps)));
            $parts[$part] = match (true) {
                in_array(FirstScan::RUNNING, $states, true) => self::RUNNING,
                // a step that failed is behind the scan too: the night tries it again
                array_diff($states, [FirstScan::DONE, FirstScan::FAILED]) === [] => self::DONE,
                default => self::WAITING,
            };
        }

        return ['finished' => $state['finished'], 'parts' => $parts];
    }

    public function start(Shop $shop): bool
    {
        $state = FirstScan::state($shop);

        // one under way goes on as it is
        if ($state !== null && ! $state['finished']) {
            return true;
        }

        return FirstScan::start($shop);
    }

    public function learned(Shop $shop): array
    {
        $notes = FirstScan::notes($shop);
        $learned = [];

        foreach (self::PART_STEPS as $part => $steps) {
            foreach ($steps as $step) {
                if (($notes[$step][0] ?? '') !== '') {
                    $learned[] = ['part' => $part, 'text' => $notes[$step][0]];
                    break;
                }
            }
        }

        return $learned;
    }
}
