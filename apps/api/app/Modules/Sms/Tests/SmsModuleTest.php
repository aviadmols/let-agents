<?php

namespace App\Modules\Sms\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class SmsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Sms');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('sms', $module->slug);
    }
}
