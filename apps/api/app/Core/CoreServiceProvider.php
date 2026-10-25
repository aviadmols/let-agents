<?php

namespace App\Core;

use App\Core\Console\CheckTranslationsCommand;
use App\Core\Console\MakeModuleCommand;
use App\Core\Console\ModuleListCommand;
use App\Core\Console\SystemCheckCommand;
use App\Core\Features\FeatureManager;
use App\Core\Features\FeatureRegistry;
use App\Core\Localization\CountingTranslator;
use App\Core\Modules\ModuleRepository;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Plans\NoCeilings;
use App\Core\Plans\PlanCeilings;
use App\Core\Settings\SettingManager;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use LogicException;

/**
 * The kernel. It knows how to find, order and register modules, and it owns the few
 * services every module shares: feature flags, settings and the tenant context.
 *
 * It never refers to a specific module. docs/ADR/0001 explains why.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRepository::class, fn () => ModuleRepository::discover(
            (string) config('upsell.modules.path'),
            (string) config('upsell.modules.namespace'),
        ));

        $this->app->singleton(FeatureRegistry::class);
        $this->app->singleton(SettingRegistry::class);

        // Scoped: a fresh instance per request, job and command under Octane and queue workers.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(FeatureManager::class);
        $this->app->scoped(SettingManager::class);
        // Until a module that keeps plans binds its own, no shop has a plan above it.
        $this->app->scoped(PlanCeilings::class, NoCeilings::class);
        // A word that agrees with its number, several numbers to a line ("{n:search/searches}").
        $this->app->extend('translator', fn (Translator $translator): Translator => $translator instanceof CountingTranslator ? $translator : CountingTranslator::from($translator));

        $this->registerModules();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'core');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTranslationsCommand::class,
                MakeModuleCommand::class,
                ModuleListCommand::class,
                SystemCheckCommand::class,
            ]);
        }
    }

    private function registerModules(): void
    {
        $modules = $this->app->make(ModuleRepository::class);
        $features = $this->app->make(FeatureRegistry::class);
        $settings = $this->app->make(SettingRegistry::class);

        foreach ($modules->enabled() as $module) {
            foreach ($module->features as $feature) {
                $features->register($feature);
            }

            foreach ($module->settings as $setting) {
                $settings->register($setting);
            }

            if ($module->provider === null) {
                continue;
            }

            if (! is_subclass_of($module->provider, ModuleServiceProvider::class)) {
                throw new LogicException("Module [{$module->name}] provider [{$module->provider}] must extend ".ModuleServiceProvider::class.'.');
            }

            /** @var ModuleServiceProvider $provider */
            $provider = new ($module->provider)($this->app);

            $this->app->register($provider->setModule($module));
        }
    }
}
