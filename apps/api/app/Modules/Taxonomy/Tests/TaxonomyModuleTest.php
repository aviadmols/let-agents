<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class TaxonomyModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Taxonomy');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('taxonomy', $module->slug);
    }
}
