<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Actions\SendStoreReports;
use Illuminate\Console\Command;

/** reports:send — the store reports due this hour, on each store's clock (SendStoreReports). */
final class SendStoreReportsCommand extends Command
{
    protected $signature = 'reports:send {--shop= : one shop, by slug or ID}';

    protected $description = 'Send the store reports due this hour: daily, weekly or monthly, at 08:00 on each store\'s clock.';

    public function handle(SendStoreReports $send): int
    {
        $this->info('Sent '.$send->handle(null, $this->option('shop') === null ? null : (string) $this->option('shop')).' reports.');

        return self::SUCCESS;
    }
}
