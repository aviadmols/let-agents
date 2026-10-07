<?php

namespace App\Modules\Connections\Console;

use App\Modules\Connections\Support\PluginPackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Builds plugins/woocommerce and copies the zip to where the app serves it from. The Docker
 * build does the same step; this is for local development.
 */
final class BundlePluginCommand extends Command
{
    protected $signature = 'connections:bundle-plugin';

    protected $description = 'Build the WooCommerce plugin zip from the monorepo and bundle it for download';

    public function handle(): int
    {
        $plugin = realpath(base_path('../../plugins/woocommerce'));

        if ($plugin === false || ! is_file($plugin.'/bin/build.php')) {
            $this->components->error('plugins/woocommerce was not found next to this app.');

            return self::FAILURE;
        }

        $build = Process::path($plugin)->run([PHP_BINARY, 'bin/build.php']);

        if ($build->failed()) {
            $this->components->error(trim($build->errorOutput() ?: $build->output()));

            return self::FAILURE;
        }

        $zips = glob($plugin.'/dist/let-agents-*.zip') ?: [];

        if ($zips === []) {
            $this->components->error('The build produced no zip.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(PluginPackage::directory());

        foreach ($zips as $zip) {
            File::copy($zip, PluginPackage::directory().DIRECTORY_SEPARATOR.basename($zip));
        }

        $package = PluginPackage::latest();
        $this->components->info("Bundled {$package?->filename} ({$package?->kilobytes()} KB).");

        return self::SUCCESS;
    }
}
