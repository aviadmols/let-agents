<?php

namespace App\Modules\Search\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * resources/search/lets-search.js is built from hebrew-search-engine.js and lets-search/ and
 * committed. It can never go stale: the same build, run here, must give the same bytes.
 */
final class LetsSearchBuildTest extends TestCase
{
    private const BUILD = __DIR__.'/../resources/search/lets-search/build.js';

    private const BUILT = __DIR__.'/../resources/search/lets-search.js';

    private static function node(): string
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            self::markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        return $node;
    }

    public function test_the_committed_file_is_what_the_build_writes(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'lets-search-').'.js';

        try {
            (new Process([self::node(), (string) realpath(self::BUILD), '--out', $out]))->mustRun();

            $this->assertTrue(
                str_replace("\r\n", "\n", (string) file_get_contents(self::BUILT)) === (string) file_get_contents($out),
                'lets-search.js is stale: run `npm run build:lets-search` from the repo root and commit the result',
            );
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -3));
        }
    }

    public function test_the_built_file_parses_and_carries_the_engine(): void
    {
        (new Process([self::node(), '--check', (string) realpath(self::BUILT)]))->mustRun();

        $built = (string) file_get_contents(self::BUILT);
        $this->assertStringContainsString('root.LA_ENGINE = engine', $built);
        $this->assertStringContainsString('win.LA_ENGINE', $built, 'the drawer reads the engine');
        $this->assertStringNotContainsString('/*@@CSS@@*/', $built, 'the stylesheets are inlined');
    }
}
