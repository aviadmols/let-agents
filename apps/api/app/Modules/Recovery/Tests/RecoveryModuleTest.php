<?php

namespace App\Modules\Recovery\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class RecoveryModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Recovery');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('recovery', $module->slug);
    }
}
