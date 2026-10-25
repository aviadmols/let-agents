<?php

namespace App\Modules\Demo\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class DemoModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Demo');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('demo', $module->slug);
    }
}
