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
        SweepDemos::end($site);
        $site->forceFill([
            'url' => 'https://'.$host, 'host' => $host, 'status' => DemoSite::SCANNING, 'error' => null, 'shop_id' => null,
            'platform' => null, 'title' => null, 'read_at' => null, 'scan_at' => null, 'shot_at' => null, 'attempts' => 0,
            'retries' => $site->retries + 1, 'expires_at' => null, 'products' => 0, 'pages' => 0,
        ])->save();
        $this->verify->begin($site);

        return 'ok';
    }
}
