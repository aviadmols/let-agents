<?php

namespace App\Modules\Assistant\Console;

use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * A shop's regression cases asked again before a change goes out, with the writer prompt at a chosen
 * version: fails (exit 1) when a case that was fixed broke. Every case is a real, paid model call.
 */
final class QualityCasesCommand extends Command
{
    protected $signature = 'assistant:quality-cases {shop : the shop, by slug or ID} {--prompt= : the answer_site prompt version, the current one when left out}';

    protected $description = 'Ask the shop\'s regression cases again and check each answer in code against what is expected.';

    public function handle(RunQualityCases $cases): int
    {
        $key = (string) $this->argument('shop');
        $shop = Shop::query()->where('slug', $key)->orWhere('id', $key)->first();

        if ($shop === null) {
            $this->error("No shop {$key}.");

            return self::FAILURE;
        }

        $version = $this->option('prompt') === null ? null : (int) $this->option('prompt');
        $totals = $cases->handle((string) $shop->id, null, $version);

        $this->line(sprintf('%d of %d passed, %d failed, $%.4f%s', $totals['passed'], $totals['ran'], $totals['failed'], $totals['cost_usd'], $totals['stopped'] !== null ? ' (stopped: '.$totals['stopped'].')' : ''));

        return $totals['failed'] === 0 && $totals['stopped'] === null ? self::SUCCESS : self::FAILURE;
    }
}
