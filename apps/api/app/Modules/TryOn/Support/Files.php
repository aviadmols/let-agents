<?php

namespace App\Modules\TryOn\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Where Try-On keeps its pictures: the disk named in config (`upsell.tryon_disk`, the Railway bucket
 * in production, the local disk elsewhere), one folder per shop and month. Nothing on it is public;
 * a picture is shown through a signed address that expires.
 */
final class Files
{
    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    /**
     * Whether every service sees the same pictures: a bucket, not one container's own disk. On a local
     * disk the web service that took the photo must also make the picture (StartTryOn).
     */
    public static function shared(): bool
    {
        return config('filesystems.disks.'.self::name().'.driver') !== 'local';
    }

    private static function name(): string
    {
        return (string) (config('upsell.tryon_disk') ?: 'local');
    }

    /** Saves a picture and returns its path. */
    public static function put(string $shopId, string $kind, string $bytes): string
    {
        $path = 'try-on/'.$shopId.'/'.now()->format('Y-m').'/'.$kind.'-'.Str::ulid().'.jpg';
        self::disk()->put($path, $bytes);

        return $path;
    }

    public static function get(?string $path): ?string
    {
        return $path !== null && self::disk()->exists($path) ? self::disk()->get($path) : null;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
        }
    }

    /** A signed address that shows the picture for a while, to the shopper or the operator. */
    public static function url(?string $path, int $minutes = 60): ?string
    {
        return $path === null ? null : URL::temporarySignedRoute('api.try_on.file', now()->addMinutes($minutes), ['p' => Crypt::encryptString($path)]);
    }
}
