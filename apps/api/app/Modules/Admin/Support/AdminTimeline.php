<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** What this module brings to the timeline: users who joined the shop's panel. */
final class AdminTimeline implements TimelineSource
{
    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $entries = [];
        $rows = User::query()->whereHas('shops', fn ($q) => $q->whereKey($shop->id)->where('shop_user.created_at', '>=', $since))
            ->with(['shops' => fn ($q) => $q->whereKey($shop->id)])->get();

        foreach ($rows as $user) {
            $pivot = $user->shops->first()?->pivot;
            $entries[] = new TimelineEntry(
                key: 'admin.user.'.$user->id,
                kind: TimelineEntry::USER,
                at: Carbon::parse($pivot?->created_at ?? $user->created_at),
                title: __('admin::timeline.user_joined', ['name' => $user->name, 'role' => ShopRole::tryFrom((string) $pivot?->role)?->label() ?? (string) $pivot?->role]),
                who: $user->name,
            );
        }

        return $entries;
    }
}
