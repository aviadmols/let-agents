<?php

namespace App\Modules\Demo\Actions;

use App\Core\Facades\Settings;
use App\Modules\Demo\Mail\DemoMail;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\DemoProgress;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A demo whose scan is done: ready, with how many products and pages it knows, for demo.keep_days, and its link
 * sent to the email (once). Asked by the scan screen as it polls and by the sweep for whoever left it; a scan that
 * has run far too long ends the same way with what it has, or fails when it found no product.
 */
final class FinishDemo
{
    /** A scan still running after this long is done with what it has. */
    private const LONGEST_HOURS = 3;

    public function handle(DemoSite $site): DemoSite
    {
        if ($site->status !== DemoSite::SCANNING || $site->shop === null) {
            return $site;
        }

        $progress = DemoProgress::of($site->shop);
        $tooLong = $site->verified_at !== null && $site->verified_at->lt(now()->subHours(self::LONGEST_HOURS));

        if (! $progress['finished'] && ! $tooLong) {
            return $site;
        }

        $products = (int) ($progress['facts']['products'] ?? 0);
        $pages = (int) ($progress['facts']['pages'] ?? 0);
        // a store shows its products; a content site may show only its pages
        if ($products === 0 && ($pages === 0 || ($site->choices['kind'] ?? 'store') === 'store')) {
            $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'no_products'])->save();

            return $site;
        }

        $site->forceFill([
            'status' => DemoSite::READY,
            'products' => $products,
            'pages' => $pages,
            'ready_at' => now(),
            'expires_at' => now()->addDays((int) Settings::get('demo.keep_days')),
        ])->save();

        // everyone who asked for this store's demo meanwhile gets it too
        DemoSite::query()->where('shop_id', $site->shop_id)->whereKeyNot($site->id)->where('status', DemoSite::SCANNING)
            ->get()->each(fn (DemoSite $other) => $other->forceFill(['status' => DemoSite::READY, 'products' => $site->products, 'pages' => $site->pages, 'ready_at' => $site->ready_at, 'expires_at' => $site->expires_at])->save());

        foreach (DemoSite::query()->where('shop_id', $site->shop_id)->where('status', DemoSite::READY)->whereNull('emailed_at')->get() as $ready) {
            try {
                Mail::to($ready->email)->locale(DemoSite::LOCALE)->send(new DemoMail('ready', $ready));
                $ready->forceFill(['emailed_at' => now()])->save();
            } catch (Throwable $e) {
                Log::warning('demo: the link could not be sent', ['demo' => $ready->id, 'error' => $e->getMessage()]);
            }
        }

        return $site->fresh();
    }
}
