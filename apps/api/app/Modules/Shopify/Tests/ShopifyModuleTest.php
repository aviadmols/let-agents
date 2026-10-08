<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class ShopifyModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Shopify');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('shopify', $module->slug);
    }
}
