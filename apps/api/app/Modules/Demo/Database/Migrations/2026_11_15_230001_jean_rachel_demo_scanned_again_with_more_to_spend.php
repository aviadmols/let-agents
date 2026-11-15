<?php

use App\Modules\Demo\Actions\RetryDemo;
use App\Modules\Demo\Actions\SweepDemos;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;

/**
 * The Jean Rachel demo Aviad made on 15.11, scanned again with $12 to spend instead of the $4 of every demo, as he
 * asked: its first scan tagged no product (no 14K, 18K or colour to filter by). Its English and its dollars are the
 * demo's own now (choices), so the store built anew keeps them. Found by its link's hash (the link stays out of the
 * code). Any other demo of the same store still shown is ended first, or the new one would join it and nothing would
 * be scanned. Nothing else is touched; a failure leaves the demo as it is and the deploy goes on.
 */
return new class extends Migration
{
    /** sha256 of the demo's link */
    private const DEMO = '153f1b378c5591a39a65c8f25059110de194d0aa287669bc4d68b4fcab01bfda';

    public function up(): void
    {
        try {
            $site = DemoSite::query()->whereNotNull('token')->get()
                ->first(fn (DemoSite $s): bool => hash('sha256', (string) $s->token) === self::DEMO);

            if ($site === null || $site->status === DemoSite::PENDING) {
                return;
            }

            $site->forceFill(['choices' => ['budget_usd' => 12, 'currency' => 'USD', 'language' => 'en'] + (array) $site->choices])->save();

            DemoSite::query()->where('host', $site->host)->whereKeyNot($site->id)
                ->whereIn('status', [DemoSite::SCANNING, DemoSite::READY])
                ->get()->each(fn (DemoSite $other) => SweepDemos::end($other));

            app(RetryDemo::class)->again($site->refresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // a demo scanned is scanned; it ends with its days
    }
};
