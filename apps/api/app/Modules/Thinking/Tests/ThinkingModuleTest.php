<?php

namespace App\Modules\Thinking\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class ThinkingModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Thinking');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('thinking', $module->slug);
    }
}
