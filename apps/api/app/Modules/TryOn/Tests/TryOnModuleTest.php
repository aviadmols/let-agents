<?php

namespace App\Modules\TryOn\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class TryOnModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('TryOn');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('try_on', $module->slug);
    }
}
