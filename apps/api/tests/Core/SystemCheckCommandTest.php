<?php

namespace Tests\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SystemCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_test_environment_passes(): void
    {
        $result = $this->runJson();

        $this->assertTrue($result['ok'], json_encode($result['checks']));
        $this->assertSame('ok', $this->statusOf($result, 'database'));
        $this->assertSame('ok', $this->statusOf($result, 'migrations'));
        $this->assertSame('ok', $this->statusOf($result, 'cache'));
        $this->assertSame('skip', $this->statusOf($result, 'redis'), 'tests do not use Redis');
    }

    public function test_pending_migrations_fail_the_check(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);

        $result = $this->runJson();

        $this->assertFalse($result['ok']);
        $this->assertSame('fail', $this->statusOf($result, 'migrations'));
    }

    public function test_production_refuses_debug_mode_and_plain_http(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://let-agents.example']);

        $result = $this->runJson();

        $this->assertFalse($result['ok']);
        $check = collect($result['checks'])->firstWhere('check', 'production settings');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('APP_DEBUG is on', $check['detail']);
        $this->assertStringContainsString('APP_URL is not https', $check['detail']);
    }

    public function test_a_redis_outage_is_reported_not_thrown(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'database' => 0],
        ]);

        $result = $this->runJson();

        $this->assertSame('fail', $this->statusOf($result, 'redis'));
    }

    public function test_the_output_never_contains_the_app_key(): void
    {
        Artisan::call('system:check', ['--json' => true]);

        $this->assertStringNotContainsString((string) config('app.key'), Artisan::output());
    }

    /** @return array{ok: bool, checks: list<array{check: string, status: string, detail: string}>} */
    private function runJson(): array
    {
        Artisan::call('system:check', ['--json' => true]);

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array{checks: list<array{check: string, status: string}>} $result */
    private function statusOf(array $result, string $check): ?string
    {
        return collect($result['checks'])->firstWhere('check', $check)['status'] ?? null;
    }
}
