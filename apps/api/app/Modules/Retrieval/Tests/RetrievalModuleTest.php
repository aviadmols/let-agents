<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class RetrievalModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Retrieval');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('retrieval', $module->slug);
    }
}
