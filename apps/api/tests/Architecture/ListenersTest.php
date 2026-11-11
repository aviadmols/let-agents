<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * An event listener takes what it works with when it runs, never in its constructor.
 *
 * Under Octane the event dispatcher builds listeners from the application as it booted, not from the request's
 * sandbox. A TenantContext (or the feature and setting managers, scoped the same way) injected there is not the
 * request's own that the tenant scope reads: a listener that ran with a shop was never seen to (11.11: Shopify's
 * customers/redact failed eight times and was dropped). An action injected there brings its own such context along.
 * The tests do not run under Octane, so nothing else catches it.
 */
final class ListenersTest extends TestCase
{
    private const SCOPED = ['TenantContext', 'FeatureManager', 'SettingManager'];

    public function test_no_listener_takes_a_request_scoped_service_or_an_action_in_its_constructor(): void
    {
        $violations = [];

        foreach (glob(app_path('Modules/*/Listeners')) ?: [] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $code, $m) !== 1) {
                    continue;
                }

                $uses = [];
                preg_match_all('/^use\s+([^;]+);/m', $code, $imports);
                foreach ($imports[1] as $import) {
                    $uses[substr((string) strrchr('\\'.$import, '\\'), 1)] = $import;
                }

                preg_match_all('/([A-Za-z_\\\\]+)\s+\$\w+/', $m[1], $params);
                foreach ($params[1] as $type) {
                    $short = substr((string) strrchr('\\'.$type, '\\'), 1);
                    $full = $uses[$short] ?? $type;

                    if (in_array($short, self::SCOPED, true) || str_contains($full, '\\Actions\\')) {
                        $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())." takes {$short} in its constructor";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Take these when the listener runs (app(...)), not in its constructor:\n".implode("\n", $violations));
    }
}
