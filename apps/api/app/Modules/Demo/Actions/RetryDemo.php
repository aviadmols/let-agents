<?php

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\PublicSite;

/**
 * A demo that hit a problem, tried again from its scan screen: the same address, or the one they corrected when
 * the screen asked whether the address is right. Their email was proven already, so no new code; the site is
 * checked as a new demo's is (StartDemo::siteProblem), the failed demo's store goes, and the demo begins again
 * (VerifyDemo::begin). A few times a demo, no more.
 */
final class RetryDemo
{
    public const MOST = 3;

    public function __construct(private readonly VerifyDemo $verify) {}

    /** @return 'ok'|'too_many'|'bad_url'|'already'|'busy'|'unreachable' */
    public function handle(DemoSite $site, ?string $url): string
    {
        if ($site->status !== DemoSite::FAILED) {
            return 'ok';
        }

        if ($site->retries >= self::MOST) {
            return 'too_many';
        }

        $host = PublicSite::host((string) ($url !== null && trim($url) !== '' ? $url : $site->url));
        $problem = $host === null ? 'bad_url' : StartDemo::siteProblem($host, null);

        if ($problem !== null) {
            return $problem;
        }

        // the failed demo's store goes (when no other demo shows it), and the demo starts over at its first step
        $this->restart($site, $host, $site->retries + 1);

        return 'ok';
    }

    /**
     * The team's "scan again", from the demos list: whatever the demo's state, its store is deleted with all it read
     * (and so is every other request's view of that store), and the demo is built and scanned anew, as it is now set
     * up (a demo from before gets what demos read today). No limit of tries: it is the team's own.
     */
    public function again(DemoSite $site): void
    {
        if ($site->status === DemoSite::PENDING) {
            return;
        }

        DemoSite::query()->where('shop_id', $site->shop_id ?? '')->whereKeyNot($site->id)->whereIn('status', [DemoSite::SCANNING, DemoSite::READY])
            ->get()->each(fn (DemoSite $other) => SweepDemos::end($other));
        $this->restart($site, $site->host, $site->retries);
    }

    private function restart(DemoSite $site, string $host, int $retries): void
    {
        SweepDemos::end($site);
        $site->forceFill([
            'url' => 'https://'.$host, 'host' => $host, 'status' => DemoSite::SCANNING, 'error' => null, 'shop_id' => null,
            'platform' => null, 'title' => null, 'read_at' => null, 'scan_at' => null, 'shot_at' => null, 'attempts' => 0,
            'retries' => $retries, 'expires_at' => null, 'products' => 0, 'pages' => 0, 'pictures' => 0, 'store_products' => null, 'ready_at' => null, 'emailed_at' => null,
            'verified_at' => now(),
        ])->save();
        $this->verify->begin($site);
    }
}
