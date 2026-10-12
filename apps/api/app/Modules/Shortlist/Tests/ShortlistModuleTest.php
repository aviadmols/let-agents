<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class ShortlistModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Shortlist');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('shortlist', $module->slug);
    }
}
