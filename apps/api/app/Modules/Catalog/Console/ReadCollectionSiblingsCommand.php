<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Actions\ReadCollectionSiblings;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:collection-siblings {shop}   one shop's collection pages, for one model's versions sold as products of their own
 *
 * A step of a shop's scan (FirstScan); never fails the scan: a store that does not answer keeps what was read before.
 */
final class ReadCollectionSiblingsCommand extends Command
{
    protected $signature = 'catalog:collection-siblings {shop : shop slug or ID}';

    protected $description = 'Read the store\'s collection pages for one model\'s versions sold as products of their own (its swatches), for the search.';

    public function handle(ReadCollectionSiblings $read): int
    {
        $key = (string) $this->argument('shop');
        $shop = Shop::query()->where('slug', $key)->orWhere('id', $key)->first();

        if ($shop === null) {
            $this->error('Shop not found.');

            return self::SUCCESS;
        }

        try {
            $run = $read->handle((string) $shop->id);
            $this->line($run === null ? "{$shop->slug}: off" : (string) $run->summary());
        } catch (Throwable $e) {
            report($e);
        }

        return self::SUCCESS;
    }
}
