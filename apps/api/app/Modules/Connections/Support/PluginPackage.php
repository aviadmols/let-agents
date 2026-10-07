<?php

namespace App\Modules\Connections\Support;

use Illuminate\Support\Carbon;

/**
 * The installable store plugin zip that ships inside this application.
 *
 * The Docker build packs plugins/woocommerce into resources/plugins, so the file served is always
 * built from the same commit as the running code. Locally, "php artisan connections:bundle-plugin"
 * does the same.
 */
final readonly class PluginPackage
{
    private function __construct(
        public string $path,
        public string $filename,
        public string $version,
        public int $bytes,
        public Carbon $builtAt,
    ) {}

    public static function directory(): string
    {
        return (string) config('upsell.plugin.path', resource_path('plugins'));
    }

    /** The newest let-agents-{version}.zip, or null when none has been bundled. */
    public static function latest(): ?self
    {
        $candidates = [];

        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'let-agents-*.zip') ?: [] as $file) {
            if (preg_match('/let-agents-(\d+\.\d+\.\d+(?:[-+][\w.]+)?)\.zip$/', $file, $m)) {
                $candidates[$m[1]] = $file;
            }
        }

        if ($candidates === []) {
            return null;
        }

        uksort($candidates, 'version_compare');
        $version = array_key_last($candidates);
        $path = $candidates[$version];

        return new self(
            path: $path,
            filename: basename($path),
            version: (string) $version,
            bytes: (int) filesize($path),
            builtAt: Carbon::createFromTimestamp((int) filemtime($path)),
        );
    }

    public function kilobytes(): string
    {
        return number_format($this->bytes / 1024, 1);
    }
}
