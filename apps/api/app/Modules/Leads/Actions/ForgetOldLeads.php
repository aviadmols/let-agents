<?php

namespace App\Modules\Leads\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Every night: a lead older than its shop's leads.retention_days is deleted, with the contact and the answers in it.
 * The count of leads a call to action brought stays in its numbers; the person does not.
 */
final class ForgetOldLeads
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return int how many leads were deleted */
    public function handle(): int
    {
        $deleted = 0;
        $shops = DB::table('leads')->distinct()->orderBy('shop_id')->pluck('shop_id');

        foreach ($shops as $shopId) {
            $before = now()->subDays((int) Settings::get('leads.retention_days', (string) $shopId));
            $deleted += (int) $this->tenant->run((string) $shopId, fn () => Lead::query()->where('created_at', '<', $before)->delete());
        }

        return $deleted;
    }
}
