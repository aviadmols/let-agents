<?php

namespace App\Modules\Taxonomy\Console;

use App\Modules\Taxonomy\Actions\FixRanges;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Moves the range tags given before whose own passage's number is outside of them to the range that holds it (FixRanges).
 *
 *   taxonomy:fix-ranges                  every shop
 *   taxonomy:fix-ranges gil-travel --dry one shop, only print what would move
 */
final class FixRangesCommand extends Command
{
    protected $signature = 'taxonomy:fix-ranges {shop? : the shop\'s slug or id} {--dry : print what would move, move nothing}';

    protected $description = 'Move range tags whose own number is outside of them to the range that holds it';

    public function handle(FixRanges $fix): int
    {
        $one = $this->argument('shop');
        foreach (Shop::query()->when($one !== null, fn ($q) => $q->where('slug', $one)->orWhere('id', $one))->get() as $shop) {
            $moved = $fix->handle($shop->id, (bool) $this->option('dry'));
            foreach ($moved as $m) {
                $this->line("  {$m['from']} -> {$m['to']}  «".mb_substr($m['evidence'], 0, 60).'»');
            }
            if ($moved !== []) {
                $this->line($shop->slug.': '.count($moved).($this->option('dry') ? ' would move' : ' moved'));
            }
        }

        return self::SUCCESS;
    }
}
