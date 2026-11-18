<?php

namespace App\Modules\SearchSubscriptions\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class SearchSubscriptionsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('SearchSubscriptions');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('search_subscriptions', $module->slug);
    }
}
