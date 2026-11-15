<?php

use App\Modules\Demo\Actions\RetryDemo;
use App\Modules\Demo\Actions\SweepDemos;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;

/**
 * The Jean Rachel demo scanned once more, now that a demo's products are read while its scan waits (not handed to a
 * batch whose answers came after it ended): its scan with $12 still tagged nothing. Its budget, English and dollars
 * stay its own (choices). Found by its link's hash; any other demo of the same store still shown ends first, or the
 * new one would join it. A failure leaves the demo as it is and the deploy goes on.
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
