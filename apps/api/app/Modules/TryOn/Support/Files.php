<?php

namespace App\Modules\TryOn\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Where Try-On keeps its pictures: the disk named in config (`upsell.tryon_disk`, the Railway bucket
 * in production, the local disk elsewhere), one folder per shop and month. Nothing on it is public;
 * a picture is shown through a signed address that expires.
 *
 * On a container's own disk (no bucket yet) every picture is also kept in the database
 * (try_on_files): another container, or a new one after a deploy, finds it there. Without that, a
 * photo saved by one container was "gone" for the one that made the picture (photo_gone).
 */
final class Files
{
    private const TABLE = 'try_on_files';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    /** Whether every service sees the same pictures: a bucket, not one container's own disk. */
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

        if (! self::shared()) {
            DB::table(self::TABLE)->insert(['path' => $path, 'bytes' => base64_encode($bytes), 'created_at' => now()]);
        }

        return $path;
    }

    public static function get(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (self::disk()->exists($path)) {
            return self::disk()->get($path);
        }

        // Saved by another container, or before a deploy: from the database, and on this disk again.
        $kept = self::shared() ? null : DB::table(self::TABLE)->where('path', $path)->value('bytes');
        $bytes = is_string($kept) ? base64_decode($kept, true) : false;

        if (! is_string($bytes)) {
            return null;
        }

        self::disk()->put($path, $bytes);

        return $bytes;
    }

    /** Whether the picture is still kept: on this disk or, without a bucket, in the database. */
    public static function exists(?string $path): bool
    {
        return $path !== null && (self::disk()->exists($path) || (! self::shared() && DB::table(self::TABLE)->where('path', $path)->exists()));
    }

    public static function delete(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
            DB::table(self::TABLE)->where('path', $path)->delete();
        }
    }

    /** A signed address that shows the picture for a while, to the shopper or the operator. */
    public static function url(?string $path, int $minutes = 60): ?string
    {
        return $path === null ? null : URL::temporarySignedRoute('api.try_on.file', now()->addMinutes($minutes), ['p' => Crypt::encryptString($path)]);
    }
}
