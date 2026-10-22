<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * The store's scan, as the first-run screens show it: where it is by part (the products, their pictures,
 * the pages, the tags, the knowledge), starting it, and what it reported, in the shop manager's words.
 * One module answers it (Runs); without it the screens show the counts alone.
 */
interface ShopScan
{
    /** The parts of the scan the first-run screens draw, in order. */
    public const PARTS = ['products', 'images', 'pages', 'tags', 'knowledge'];

    public const DONE = 'done';

    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    /**
     * Where the scan started this week is, or null when none was.
     *
     * @return array{finished: bool, parts: array<string, string>}|null part => done, running or waiting
     */
    public function progress(Shop $shop): ?array;

    /** Starts the scan unless one is under way; false when it may not start yet. */
    public function start(Shop $shop): bool;

    /**
     * What the scan reported so far, a line a part at most, never a model, a cost or a mechanism.
     *
     * @return list<array{part: string, text: string}>
     */
    public function learned(Shop $shop): array;
}
