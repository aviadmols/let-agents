<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   search:forget-visits   the address and the session of searches and photo searches older than
 *                          search.visit_keep_days, cleared in every shop; what was searched stays
 */
final class ForgetVisitsCommand extends Command
{
    protected $signature = 'search:forget-visits';

    protected $description = 'Clear the IP address and the session of searches older than search.visit_keep_days.';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) Settings::get('search.visit_keep_days')));
        $cleared = 0;

        foreach (['search_logs', 'search_photo_asks'] as $table) {
            $cleared += DB::table($table)->where('created_at', '<', $before)
                ->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('session_id'))
                ->update(['ip' => null, 'session_id' => null]);
        }

        $this->line("{$cleared} cleared");

        return self::SUCCESS;
    }
}
