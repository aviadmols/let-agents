<?php

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Jobs\PrepareDemoJob;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Support\Facades\Hash;

/**
 * The code from the email, checked: a few tries, a few minutes. Right, the email is theirs and the demo is built
 * (BuildDemoStore), or, when the store already has a live demo, they get that one: one demo a store at a time.
 */
final class VerifyDemo
{
    public function __construct(private readonly BuildDemoStore $build) {}

    /** @return 'ok'|'wrong'|'expired'|'locked'|'done' */
    public function handle(DemoSite $site, string $code): string
    {
        if ($site->status !== DemoSite::PENDING) {
            return 'done';
        }

        if ($site->code_tries >= StartDemo::CODE_TRIES) {
            return 'locked';
        }

        if ($site->code_expires_at === null || $site->code_expires_at->isPast()) {
            return 'expired';
        }

        if (! Hash::check(preg_replace('/\D/', '', $code), (string) $site->code_hash)) {
            $site->increment('code_tries');

            return 'wrong';
        }

        $site->forceFill(['verified_at' => now(), 'code_hash' => null])->save();

        $live = DemoSite::query()->where('host', $site->host)->whereKeyNot($site->id)->whereNotNull('shop_id')
            ->whereIn('status', [DemoSite::SCANNING, DemoSite::READY])->latest()->first();

        if ($live !== null && $live->live()) {
            // the store's demo already under way or ready: theirs too, with its picture and its end
            $site->forceFill(['shop_id' => $live->shop_id, 'status' => $live->status, 'platform' => $live->platform, 'title' => $live->title,
                'screenshot' => $live->screenshot, 'screenshot_mobile' => $live->screenshot_mobile, 'products' => $live->products, 'pages' => $live->pages,
                'ready_at' => $live->ready_at, 'expires_at' => $live->expires_at])->save();

            return 'ok';
        }

        $this->build->handle($site);
        PrepareDemoJob::dispatch($site->id)->onQueue('long');

        return 'ok';
    }
}
