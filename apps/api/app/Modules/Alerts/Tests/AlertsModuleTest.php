<?php

namespace App\Modules\Alerts\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class AlertsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Alerts');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('alerts', $module->slug);
    }
}
