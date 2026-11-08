<?php

namespace Tests;

use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The tests read the panels in Hebrew, as they were written; the product's default is English
        // (admin.default_locale). Only the default is changed, in memory: nothing is stored, and a test
        // about the default sets the value it means (Settings::set).
        $registry = $this->app->make(SettingRegistry::class);
        if ($registry->has('admin.default_locale')) {
            $d = $registry->get('admin.default_locale');
            $definitions = new ReflectionProperty(SettingRegistry::class, 'definitions');
            $all = $definitions->getValue($registry);
            $all['admin.default_locale'] = new SettingDefinition($d->module, $d->name, $d->type, 'he', $d->scope, $d->min, $d->max, $d->options, $d->unit);
            $definitions->setValue($registry, $all);
        }
    }
}
