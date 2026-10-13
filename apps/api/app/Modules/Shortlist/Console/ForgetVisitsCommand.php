<?php

namespace App\Modules\Shortlist\Console;

use App\Core\Facades\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   shortlist:forget-visits   the address and the session of wishlists unchanged for
 *                             shortlist.visit_keep_days, cleared in every shop; the lists stay
 */
final class ForgetVisitsCommand extends Command
{
    protected $signature = 'shortlist:forget-visits';

    protected $description = 'Clear the IP address and the session of wishlists unchanged for shortlist.visit_keep_days.';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) Settings::get('shortlist.visit_keep_days')));

        // The table directly: clearing them is not a change of the list, its time stays.
        $cleared = DB::table('shortlist_wishlists')->where('updated_at', '<', $before)
            ->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('session_id'))
            ->update(['ip' => null, 'session_id' => null]);

        $this->line("{$cleared} cleared");

        return self::SUCCESS;
    }
}
