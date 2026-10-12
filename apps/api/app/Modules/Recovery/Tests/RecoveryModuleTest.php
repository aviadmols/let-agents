<?php

namespace App\Modules\Recovery\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class RecoveryModuleTest extends TestCase
{
    public function test_module_is_discovered_and_switched_off(): void
    {
        $module = app(ModuleRepository::class)->get('Recovery');

        $this->assertNotNull($module);
        // Abandoned carts are off for every shop, at the owner's word; its code stays for a later return.
        $this->assertFalse($module->enabled);
        $this->assertSame('recovery', $module->slug);
    }
}
